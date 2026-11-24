<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Core\Traits\PDFHandler;
use Webkul\Fiscal\Models\FiscalDocument;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\PartInventory\Models\Part;
use Webkul\PartInventory\Models\Service;
use Webkul\ServiceOrder\DataGrids\ServiceOrderDataGrid;
use Webkul\ServiceOrder\Models\DiagnosticoQueixaTipo;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Repositories\ServiceOrderRepository;
use Webkul\ServiceOrder\Services\ClienteBusca;
use Webkul\ServiceOrder\Services\ExtrasOs;
use Webkul\ServiceOrder\Services\PrevisaoOs;
use Webkul\ServiceOrder\Services\ServiceOrderItemService;
use Webkul\ServiceOrder\Services\StageAdvanceService;
use Webkul\ServiceOrder\Services\StageGate;
use Webkul\ServiceOrder\Services\ValoresFinanceiros;
use Webkul\ServiceOrder\Services\ValorHoraService;
use Webkul\Vehicle\Models\Vehicle;

class ServiceOrderController extends Controller
{
    use PDFHandler;

    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected ServiceOrderRepository $serviceOrderRepository,
        protected ServiceOrderItemService $serviceOrderItemService,
        protected PersonRepository $personRepository
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(ServiceOrderDataGrid::class)->process();
        }

        return view('service_order::index');
    }

    /**
     * One of the 9 pipeline stage screens (menu items 1-9): the same
     * DataGrid, filtered to a single workshop_status. The stage comes from
     * the menu's own route params (see Config/menu.php), not user input.
     */
    /**
     * Chave de acesso de cada etapa (a lista de cada etapa é um item do menu).
     */
    protected const CHAVE_DA_ETAPA = [
        ServiceOrder::STAGE_CADASTRO => 'cadastro',
        ServiceOrder::STAGE_ORDEM_SERVICO => 'ordem_servico',
        ServiceOrder::STAGE_DIAGNOSTICO => 'diagnostico',
        ServiceOrder::STAGE_ORCAMENTO => 'orcamento',
        ServiceOrder::STAGE_NEGOCIACAO => 'negociacao',
        ServiceOrder::STAGE_EM_EXECUCAO => 'em_execucao',
        ServiceOrder::STAGE_VEICULO_TESTE => 'veiculo_teste',
        ServiceOrder::STAGE_FATURAMENTO => 'faturamento',
        ServiceOrder::STAGE_POS_VENDA => 'pos_venda',
    ];

    /**
     * Cada perfil abre só as etapas do seu trabalho (B8 do roteiro). Com o piloto desligado vale o
     * de sempre: quem tem a chave "pos_venda" abre qualquer lista (era o que a rota exigia).
     */
    protected function autorizarEtapa(?string $etapa): void
    {
        if (! StageGate::active()) {
            // hasPermission() (e não Bouncer::allow) para o administrador de função "todas as permissões" passar:
            // allow() lê a lista de permissões, que é nula nessa função, e dava erro 500 em todas as listas de etapa.
            abort_unless(bouncer()->hasPermission('pos_venda'), 403, 'This action is unauthorized');

            return;
        }

        $chave = self::CHAVE_DA_ETAPA[$etapa ?? ServiceOrder::STAGE_CADASTRO] ?? 'service_orders';

        abort_unless(bouncer()->hasPermission($chave), 403, 'Sua função não abre esta etapa.');
    }

    public function stageIndex(?string $etapa = null): View|JsonResponse
    {
        $this->autorizarEtapa($etapa);

        if (request()->ajax()) {
            return datagrid(ServiceOrderDataGrid::class)->process();
        }

        return view('service_order::stage-index', [
            'stage' => $etapa ?? ServiceOrder::STAGE_CADASTRO,
            'stages' => ServiceOrder::stagesFlow(),
        ]);
    }

    /**
     * Move an order forward to the next stage in the flow, or into
     * Cancelada from anywhere. Mirrors admin.leads.stage.update in spirit.
     */
    public function updateStage(int $id, StageAdvanceService $advancer): RedirectResponse
    {
        $this->validate(request(), [
            'stage' => 'required|string',
            'forcar' => 'nullable|boolean',
            'justificativa' => 'nullable|string|max:500',
        ]);

        $serviceOrder = $this->serviceOrderRepository->findOrFail($id);

        if (! $serviceOrder->canAdvanceTo(request('stage'))) {
            session()->flash('error', 'Essa ordem de serviço não pode avançar para essa etapa agora.');

            return redirect()->route('admin.service_orders.view', $id);
        }

        $force = request()->boolean('forcar');

        // Só quem tem a permissão pode forçar o avanço (sempre com justificativa).
        abort_if($force && ! bouncer()->hasPermission('service_orders.forcar_avanco'), 403);

        try {
            $result = $advancer->advance($serviceOrder, request('stage'), $force, request('justificativa'));
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first());

            return redirect()->route('admin.service_orders.view', $id);
        }

        if (! $result['avancou']) {
            session()->flash('error', 'Não é possível avançar ainda. Falta: '.implode('; ', $result['faltando']).'.');

            return redirect()->route('admin.service_orders.view', $id);
        }

        session()->flash('success', $result['forcado']
            ? 'Ordem de serviço avançada à força para "'.request('stage').'". O gerente foi avisado.'
            : 'Ordem de serviço avançada para "'.request('stage').'".');

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $mechanics = Mechanic::orderBy('nome')->get();

        // O cliente vem da pesquisa do formulário; já chega escolhido quando veio da aba "Buscar cliente"
        // (?person_id=) ou quando o formulário voltou com erro.
        $clienteId = (int) (old('person_id') ?: request('person_id'));
        $clienteInicial = $clienteId ? app(ClienteBusca::class)->porId($clienteId) : null;

        return view('service_order::create', compact('mechanics', 'clienteInicial'));
    }

    /**
     * Return the vehicles that belong to a given person (used by the create/edit form).
     */
    public function vehiclesByPerson(int $personId): JsonResponse
    {
        return response()->json(
            Vehicle::where('person_id', $personId)->get(['id', 'placa', 'marca', 'modelo'])
        );
    }

    /**
     * Mensagens, em português, dos campos de previsão de conclusão.
     *
     * @return array<string, string>
     */
    protected function mensagensPrevisao(): array
    {
        return [
            'data_previsao.date' => 'A previsão de conclusão precisa ser uma data válida.',
            'hora_previsao.date_format' => 'O horário da previsão precisa estar no formato HH:MM (por exemplo, 15:30).',
        ];
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse
    {
        $this->validate(request(), [
            'person_id' => 'required|integer|exists:persons,id',
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'mechanic_id' => 'nullable|integer|exists:mechanics,id',
            'servico' => 'required|string|max:255',
            'description' => 'nullable|string',
            'relato_motorista' => 'nullable|string|max:2000',
            'defeito_relatado' => 'nullable|string|max:2000',
            'data_previsao' => 'nullable|date',
            'hora_previsao' => 'nullable|date_format:H:i',
        ] + ExtrasOs::regras(), $this->mensagensPrevisao() + ExtrasOs::mensagens());

        Event::dispatch('service_order.create.before');

        // "Como chegou à empresa" só se pergunta no Novo Cadastro. Aqui a OS
        // herda a origem já registrada para o cliente (a primeira que ele teve).
        $serviceOrder = $this->serviceOrderRepository->create(PrevisaoOs::ajustar(array_merge($this->dadosDaOs(), [
            'origem_cliente' => ServiceOrder::origemDoCliente((int) request('person_id')),
        ])));

        Event::dispatch('service_order.create.after', $serviceOrder);

        // O "+" do formulário: anotação, fotos, vídeos e outros arquivos (opcionais).
        app(ExtrasOs::class)->registrarDoFormulario($serviceOrder, request());

        session()->flash('success', trans('service_order::app.index.create-success'));

        return redirect()->route('admin.service_orders.view', $serviceOrder->id);
    }

    /**
     * Display the specified resource.
     */
    public function view(int $id): View
    {
        $serviceOrder = $this->serviceOrderRepository->findOrFail($id);

        $authorizedUserIds = bouncer()->getAuthorizedUserIds();

        if ($authorizedUserIds !== null) {
            $mechanicId = auth()->guard('user')->user()?->mechanic_id;

            $isOwnOrder = in_array($serviceOrder->user_id, $authorizedUserIds)
                || ($mechanicId && $serviceOrder->mechanic_id == $mechanicId);

            abort_unless($isOwnOrder, 403);
        }

        $canSeeValues = ValoresFinanceiros::can();

        $serviceOrder->load([
            'person', 'vehicle', 'mechanic', 'items.part', 'items.funcionario',
            'checklistItems', 'timeLogs', 'feedback', 'testeMechanic',
            'attachments', 'laudoItems.attachment', 'queixaTipo',
        ]);

        // Cobranças só são carregadas para quem pode ver valores.
        if ($canSeeValues) {
            $serviceOrder->load('boletos');
        }

        $parts = Part::orderBy('nome')->get();
        $services = Service::orderBy('nome')->get();
        $mechanics = Mechanic::orderBy('nome')->get();

        $fiscalDocument = $canSeeValues ? FiscalDocument::where('quote_id', $id)->where('type', 'nfse')->latest()->first() : null;
        $fiscalDocumentNfe = $canSeeValues ? FiscalDocument::where('quote_id', $id)->where('type', 'nfe')->latest()->first() : null;

        $pilot = StageGate::active();

        $queixaTipos = collect();

        if ($pilot) {
            DiagnosticoQueixaTipo::ensureDefaults();
            $queixaTipos = DiagnosticoQueixaTipo::where('ativo', true)->orderBy('nome')->get();
        }

        return view('service_order::view', compact(
            'serviceOrder', 'parts', 'services', 'mechanics', 'fiscalDocument', 'fiscalDocumentNfe',
            'canSeeValues', 'pilot', 'queixaTipos'
        ));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $serviceOrder = $this->serviceOrderRepository->findOrFail($id);

        $mechanics = Mechanic::orderBy('nome')->get();
        $vehicles = Vehicle::where('person_id', $serviceOrder->person_id)->get();

        // O cliente da OS já chega escolhido no campo de pesquisa (ou o que foi escolhido antes de um erro do formulário).
        $clienteId = (int) (old('person_id') ?: $serviceOrder->person_id);
        $clienteInicial = $clienteId ? app(ClienteBusca::class)->porId($clienteId) : null;

        return view('service_order::edit', compact('serviceOrder', 'clienteInicial', 'mechanics', 'vehicles'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(int $id): RedirectResponse
    {
        $this->validate(request(), [
            'person_id' => 'required|integer|exists:persons,id',
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'mechanic_id' => 'nullable|integer|exists:mechanics,id',
            'servico' => 'required|string|max:255',
            'description' => 'nullable|string',
            'relato_motorista' => 'nullable|string|max:2000',
            'defeito_relatado' => 'nullable|string|max:2000',
            'data_previsao' => 'nullable|date',
            'hora_previsao' => 'nullable|date_format:H:i',
        ] + ExtrasOs::regras(), $this->mensagensPrevisao() + ExtrasOs::mensagens());

        Event::dispatch('service_order.update.before', $id);

        $serviceOrder = $this->serviceOrderRepository->update(PrevisaoOs::ajustar($this->dadosDaOs()), $id);

        Event::dispatch('service_order.update.after', $serviceOrder);

        app(ExtrasOs::class)->registrarDoFormulario($serviceOrder, request());

        session()->flash('success', trans('service_order::app.index.update-success'));

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Aba "Defeito relatado pelo motorista": salva só o relato e o defeito, sem abrir a edição da OS.
     */
    public function updateDefeito(int $id): RedirectResponse
    {
        $this->validate(request(), [
            'relato_motorista' => 'nullable|string|max:2000',
            'defeito_relatado' => 'nullable|string|max:2000',
        ]);

        $campos = collect(['relato_motorista', 'defeito_relatado'])
            ->filter(fn (string $coluna) => Schema::hasColumn('quotes', $coluna))
            ->mapWithKeys(fn (string $coluna) => [$coluna => request($coluna) ?: null])
            ->all();

        $serviceOrder = $this->serviceOrderRepository->update($campos, $id);

        Event::dispatch('service_order.update.after', $serviceOrder);

        session()->flash('success', trans('service_order::app.defeito.salvo'));

        return redirect()->route('admin.service_orders.view', $id)->withFragment('defeito-relatado');
    }

    /**
     * Dados do formulário da OS. Bancos que ainda não receberam a migration dos
     * campos "Relato do motorista" / "Defeito relatado" seguem salvando a OS sem eles.
     *
     * @return array<string, mixed>
     */
    protected function dadosDaOs(): array
    {
        // Os campos do "+" (extra_*) têm destino próprio: não entram nos dados da OS.
        $dados = request()->except(['extra_titulo', 'extra_texto', 'extra_fotos', 'extra_videos', 'extra_arquivos']);

        foreach (['relato_motorista', 'defeito_relatado'] as $coluna) {
            if (! Schema::hasColumn('quotes', $coluna)) {
                unset($dados[$coluna]);
            }
        }

        return $dados;
    }

    /**
     * Update just the workshop status (triggers the ServiceOrderObserver's stock/lock logic).
     */
    public function updateStatus(int $id): RedirectResponse
    {
        $this->validate(request(), [
            'workshop_status' => 'required|in:Aberta,Autorizado,Em Andamento,Aguardando Peças,Concluída,Cancelada',
        ]);

        try {
            $this->serviceOrderRepository->update(['workshop_status' => request('workshop_status')], $id);

            session()->flash('success', trans('service_order::app.view.status-updated'));
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first());
        }

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->serviceOrderRepository->findOrFail($id);

        try {
            Event::dispatch('service_order.delete.before', $id);

            $this->serviceOrderRepository->delete($id);

            Event::dispatch('service_order.delete.after', $id);

            return response()->json([
                'message' => trans('service_order::app.index.delete-success'),
            ], 200);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('service_order::app.index.delete-failed'),
            ], 400);
        }
    }

    /**
     * Add a line item (Peça or Serviço) to the service order.
     */
    public function storeItem(int $id): RedirectResponse
    {
        // Lançar item exige informar preço: quem não vê valores usa a tela do técnico.
        ValoresFinanceiros::authorize('lançar item com preço');

        $serviceOrder = $this->serviceOrderRepository->findOrFail($id);

        $this->validate(request(), [
            'tipo' => 'required|in:Peça,Serviço',
            'descricao' => 'required|string|max:255',
            // Serviço com o piloto ligado pode vir sem valor: entra o valor da hora da Ficha (B5).
            'preco_unitario' => 'required_unless:tipo,Serviço|nullable|numeric|min:0',
            'quantidade' => 'required_if:tipo,Peça|nullable|integer|min:1',
            'part_id' => 'required_if:tipo,Peça|nullable|integer|exists:parts,id',
            'hours' => 'nullable|numeric|min:0',
            'funcionario_id' => 'nullable|integer|exists:mechanics,id',
            'service_id' => 'nullable|integer|exists:services,id',
        ]);

        $valorHora = app(ValorHoraService::class);
        $dados = $valorHora->completar(request()->all());

        if (($dados['tipo'] ?? null) === 'Serviço' && ! filled($dados['preco_unitario'] ?? null)) {
            throw ValidationException::withMessages(['preco_unitario' => ['Informe o valor unitário do serviço.']]);
        }

        // Abaixo do preço mínimo da hora pede alçada (bloqueia); acima de N vezes a Ficha só avisa.
        $aviso = $valorHora->conferir($serviceOrder, $dados);

        $this->serviceOrderItemService->addItem($serviceOrder, $dados);

        session()->flash('success', trans('service_order::app.view.item-added'));

        if ($aviso) {
            session()->flash('warning', $aviso);
        }

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Remove a line item from the service order.
     */
    public function destroyItem(int $id, int $itemId): RedirectResponse
    {
        $item = ServiceOrderItem::where('quote_id', $id)->findOrFail($itemId);

        $this->serviceOrderItemService->removeItem($item);

        session()->flash('success', trans('service_order::app.view.item-removed'));

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Print the service order as a PDF (reusing the PDFHandler trait).
     */
    public function print(int $id)
    {
        $serviceOrder = $this->serviceOrderRepository->findOrFail($id);
        $serviceOrder->load(['person', 'vehicle', 'mechanic', 'items.part', 'user']);

        return $this->downloadPDF(
            view('service_order::print', compact('serviceOrder'))->render(),
            'OS_'.$serviceOrder->id.'_'.$serviceOrder->created_at->format('d-m-Y')
        );
    }
}
