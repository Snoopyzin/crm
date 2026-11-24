<?php

namespace Webkul\Fiscal\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Models\ApiKey;
use Webkul\Fiscal\DataGrids\FiscalDocumentDataGrid;
use Webkul\Fiscal\Models\FiscalDocument;
use Webkul\Fiscal\Models\FiscalSettings;
use Webkul\Fiscal\Services\FocusNfeClient;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Repositories\ServiceOrderRepository;

class FiscalController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected FocusNfeClient $focusNfeClient,
        protected ServiceOrderRepository $serviceOrderRepository
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(FiscalDocumentDataGrid::class)->process();
        }

        return view('fiscal::index');
    }

    /**
     * Display the specified resource, including its request/webhook log trail.
     */
    public function view(int $id): View
    {
        $fiscalDocument = FiscalDocument::with('logs')->findOrFail($id);

        return view('fiscal::view', compact('fiscalDocument'));
    }

    /**
     * Show a confirmation screen with the exact values that will be sent to
     * Focus NFe before actually emitting -- this is a real, hard-to-undo
     * fiscal document, so nothing gets sent without the shop seeing and
     * confirming the values first.
     */
    public function create(int $serviceOrderId): View|RedirectResponse
    {
        $serviceOrder = $this->serviceOrderRepository->findOrFail($serviceOrderId);
        $serviceOrder->load(['person', 'items']);

        $missing = $this->missingRequirements($serviceOrder);

        if (! empty($missing)) {
            session()->flash('error', 'Não é possível emitir nota fiscal ainda: '.implode(' ', $missing));

            return redirect()->route('admin.service_orders.view', $serviceOrderId);
        }

        $fiscalSettings = FiscalSettings::current();

        $valorSugerido = $serviceOrder->items
            ->where('tipo', ServiceOrderItem::TIPO_SERVICO)
            ->sum('total');

        if ($valorSugerido <= 0) {
            $valorSugerido = $serviceOrder->grand_total;
        }

        $discriminacaoSugerida = trim(collect([
            $serviceOrder->subject,
            $serviceOrder->items->where('tipo', ServiceOrderItem::TIPO_SERVICO)->pluck('name')->implode(', '),
        ])->filter()->implode(' -- '));

        return view('fiscal::create', [
            'serviceOrder' => $serviceOrder,
            'fiscalSettings' => $fiscalSettings,
            'valorSugerido' => $valorSugerido,
            'discriminacaoSugerida' => $discriminacaoSugerida,
        ]);
    }

    /**
     * Emit a NFS-e for a completed service order.
     *
     * This is a deliberate manual action (not automatic on status change),
     * since fiscal emission has real legal/financial consequences.
     *
     * NOTE: cannot be verified end-to-end without a real Focus NFe account
     * and API token -- each tenant configures their own under Chaves de API.
     */
    public function emitForServiceOrder(int $serviceOrderId): RedirectResponse
    {
        $serviceOrder = $this->serviceOrderRepository->findOrFail($serviceOrderId);
        $serviceOrder->load(['person', 'items']);

        $missing = $this->missingRequirements($serviceOrder);

        if (! empty($missing)) {
            session()->flash('error', 'Não é possível emitir nota fiscal ainda: '.implode(' ', $missing));

            return redirect()->route('admin.service_orders.view', $serviceOrderId);
        }

        $this->validate(request(), [
            'valor_servicos' => 'required|numeric|min:0.01',
            'discriminacao' => 'required|string|max:2000',
        ]);

        $payload = $this->buildNfsePayload($serviceOrder, FiscalSettings::current());

        try {
            $document = $this->focusNfeClient->emitNfse($payload);

            session()->flash(
                $document->status === FiscalDocument::STATUS_ERRO ? 'error' : 'success',
                trans('fiscal::app.emit-result', ['status' => $document->status])
            );
        } catch (\Exception $exception) {
            session()->flash('error', trans('fiscal::app.emit-failed', ['message' => $exception->getMessage()]));
        }

        return redirect()->route('admin.service_orders.view', $serviceOrderId);
    }

    /**
     * Show a confirmation screen for a NF-e (product invoice, modelo 55) --
     * same "confirm before sending" pattern as the NFS-e create(), but
     * listing the Peça line items (with NCM/CFOP) instead of a single
     * valor/discriminação pair.
     */
    public function createNfe(int $serviceOrderId): View|RedirectResponse
    {
        $serviceOrder = $this->serviceOrderRepository->findOrFail($serviceOrderId);
        $serviceOrder->load(['person', 'items.part']);

        $missing = $this->missingRequirementsNfe($serviceOrder);

        if (! empty($missing)) {
            session()->flash('error', 'Não é possível emitir nota fiscal de produto ainda: '.implode(' ', $missing));

            return redirect()->route('admin.service_orders.view', $serviceOrderId);
        }

        return view('fiscal::create-nfe', [
            'serviceOrder' => $serviceOrder,
            'fiscalSettings' => FiscalSettings::current(),
            'pecaItems' => $serviceOrder->items->where('tipo', ServiceOrderItem::TIPO_PECA),
        ]);
    }

    /**
     * Emit a NF-e (product invoice) for the Peça items on a service order.
     *
     * Same manual-confirmation rule as NFS-e -- this is a real fiscal
     * document with legal/financial consequences, never automatic.
     *
     * NOTE: cannot be verified end-to-end without a real Focus NFe account
     * with a digital certificate registered on it. The tax block below
     * (CSOSN/ICMS) assumes Simples Nacional -- confirm with your accountant
     * before relying on this for a real emission.
     */
    public function emitNfeForServiceOrder(int $serviceOrderId): RedirectResponse
    {
        $serviceOrder = $this->serviceOrderRepository->findOrFail($serviceOrderId);
        $serviceOrder->load(['person', 'items.part']);

        $missing = $this->missingRequirementsNfe($serviceOrder);

        if (! empty($missing)) {
            session()->flash('error', 'Não é possível emitir nota fiscal de produto ainda: '.implode(' ', $missing));

            return redirect()->route('admin.service_orders.view', $serviceOrderId);
        }

        $payload = $this->buildNfePayload($serviceOrder, FiscalSettings::current());

        try {
            $document = $this->focusNfeClient->emitNfe($payload);

            session()->flash(
                $document->status === FiscalDocument::STATUS_ERRO ? 'error' : 'success',
                trans('fiscal::app.emit-result', ['status' => $document->status])
            );
        } catch (\Exception $exception) {
            session()->flash('error', trans('fiscal::app.emit-failed', ['message' => $exception->getMessage()]));
        }

        return redirect()->route('admin.service_orders.view', $serviceOrderId);
    }

    /**
     * Everything Focus NFe/SEFAZ requires for a NF-e that isn't captured
     * anywhere yet, in friendly Portuguese with links.
     *
     * @return array<int, string>
     */
    protected function missingRequirementsNfe(ServiceOrder $serviceOrder): array
    {
        $missing = [];

        if (! ApiKey::focusNfeToken()) {
            $missing[] = 'Falta cadastrar a chave da Focus NFe em <a href="'.route('admin.settings.api_keys.create').'" class="underline">Chaves de API</a>.';
        }

        if (! FiscalSettings::current()->isReadyForNfeEmission()) {
            $missing[] = 'Falta completar o CNPJ/Inscrição Estadual/regime tributário da oficina em <a href="'.route('admin.settings.fiscal_settings.edit').'" class="underline">Configurações Fiscais</a>.';
        }

        $cpfCnpj = preg_replace('/\D/', '', $serviceOrder->person->cpf_cnpj ?? '');

        if (! in_array(strlen($cpfCnpj), [11, 14])) {
            $missing[] = 'Falta o CPF/CNPJ do cliente "'.$serviceOrder->person->name.'" -- edite a pessoa e preencha em "Sobre a pessoa".';
        }

        $pecaItems = $serviceOrder->items->where('tipo', ServiceOrderItem::TIPO_PECA);

        if ($pecaItems->isEmpty()) {
            $missing[] = 'Essa OS não tem nenhum item do tipo "Peça" -- NF-e de produto é só pra venda de peças, não de serviço (isso já vai na NFS-e).';
        }

        $pecasSemNcm = $pecaItems->filter(fn ($item) => blank($item->part?->ncm))->pluck('name');

        if ($pecasSemNcm->isNotEmpty()) {
            $missing[] = 'Falta o NCM das peças: '.$pecasSemNcm->implode(', ').' -- edite a peça em Estoque e preencha o NCM.';
        }

        return $missing;
    }

    /**
     * Build the Focus NFe NF-e request payload from a service order's Peça
     * items. Field names follow Focus NFe's documented NF-e schema --
     * confirm against a real account once one exists. The ICMS block
     * assumes CSOSN 102 (Simples Nacional, sem crédito), the most common
     * case for a small oficina -- confirm with your accountant if that's
     * not your case.
     */
    protected function buildNfePayload(ServiceOrder $serviceOrder, FiscalSettings $fiscalSettings): array
    {
        $person = $serviceOrder->person;
        $cpfCnpj = preg_replace('/\D/', '', $person->cpf_cnpj ?? '');

        $destinatario = [
            'nome' => $person->name,
            'indicador_inscricao_estadual' => 9,
        ];

        if (strlen($cpfCnpj) === 14) {
            $destinatario['cnpj'] = $cpfCnpj;
        } else {
            $destinatario['cpf'] = $cpfCnpj;
        }

        $pecaItems = $serviceOrder->items->where('tipo', ServiceOrderItem::TIPO_PECA)->values();

        $items = $pecaItems->map(function ($item, $index) use ($fiscalSettings) {
            $valorUnitario = round((float) $item->price, 2);
            $quantidade = (float) $item->quantity;
            $valorBruto = round($valorUnitario * $quantidade, 2);

            return [
                'numero_item' => (string) ($index + 1),
                'codigo_produto' => $item->sku ?: (string) $item->part_id,
                'descricao' => $item->name,
                'codigo_ncm' => preg_replace('/\D/', '', $item->part?->ncm ?? ''),
                'cfop' => $fiscalSettings->cfop_padrao,
                'unidade_comercial' => 'UN',
                'quantidade_comercial' => (string) $quantidade,
                'valor_unitario_comercial' => (string) $valorUnitario,
                'valor_bruto' => (string) $valorBruto,
                'unidade_tributavel' => 'UN',
                'quantidade_tributavel' => (string) $quantidade,
                'valor_unitario_tributavel' => (string) $valorUnitario,
                'icms_situacao_tributaria' => '102',
                'icms_origem' => '0',
                'pis_situacao_tributaria' => '07',
                'cofins_situacao_tributaria' => '07',
            ];
        })->all();

        $valorTotal = round(collect($items)->sum(fn ($item) => (float) $item['valor_bruto']), 2);

        return [
            'quote_id' => $serviceOrder->id,
            'natureza_operacao' => 'Venda de mercadoria',
            'data_emissao' => now()->toIso8601String(),
            'tipo_documento' => 1,
            'finalidade_emissao' => 1,
            'cnpj_emitente' => preg_replace('/\D/', '', $fiscalSettings->cnpj ?? ''),
            'nome_emitente' => $fiscalSettings->razao_social,
            'nome_fantasia_emitente' => $fiscalSettings->nome_fantasia,
            'inscricao_estadual_emitente' => $fiscalSettings->inscricao_estadual,
            'uf_emitente' => $fiscalSettings->uf,
            'regime_tributario_emitente' => $fiscalSettings->regime_tributario,
            'destinatario' => $destinatario,
            'items' => $items,
            'valor_produtos' => (string) $valorTotal,
            'valor_total' => (string) $valorTotal,
        ];
    }

    /**
     * Everything Focus NFe requires that isn't captured anywhere yet.
     * Returns friendly Portuguese messages (with links) instead of letting a
     * real API call fail with a cryptic error.
     *
     * @return array<int, string>
     */
    protected function missingRequirements(ServiceOrder $serviceOrder): array
    {
        $missing = [];

        if (! ApiKey::focusNfeToken()) {
            $missing[] = 'Falta cadastrar a chave da Focus NFe em <a href="'.route('admin.settings.api_keys.create').'" class="underline">Chaves de API</a>.';
        }

        if (! FiscalSettings::current()->isReadyForEmission()) {
            $missing[] = 'Falta completar o CNPJ/Inscrição Municipal/código de serviço da oficina em <a href="'.route('admin.settings.fiscal_settings.edit').'" class="underline">Configurações Fiscais</a>.';
        }

        $cpfCnpj = preg_replace('/\D/', '', $serviceOrder->person->cpf_cnpj ?? '');

        if (! in_array(strlen($cpfCnpj), [11, 14])) {
            $missing[] = 'Falta o CPF/CNPJ do cliente "'.$serviceOrder->person->name.'" -- edite a pessoa e preencha em "Sobre a pessoa".';
        }

        return $missing;
    }

    /**
     * Build the Focus NFe NFS-e request payload from a service order. Field
     * names follow Focus NFe's documented NFS-e schema (prestador/tomador/
     * servico) -- confirm against a real account once one exists.
     */
    protected function buildNfsePayload(ServiceOrder $serviceOrder, FiscalSettings $fiscalSettings): array
    {
        $person = $serviceOrder->person;
        $cpfCnpj = preg_replace('/\D/', '', $person->cpf_cnpj ?? '');

        $tomador = [
            'razao_social' => $person->name,
        ];

        if (strlen($cpfCnpj) === 14) {
            $tomador['cnpj'] = $cpfCnpj;
        } else {
            $tomador['cpf'] = $cpfCnpj;
        }

        return [
            'quote_id' => $serviceOrder->id,
            'data_emissao' => now()->toIso8601String(),
            'natureza_operacao' => '1',
            'optante_simples_nacional' => (bool) $fiscalSettings->optante_simples_nacional,
            'prestador' => [
                'cnpj' => preg_replace('/\D/', '', $fiscalSettings->cnpj ?? ''),
                'inscricao_municipal' => $fiscalSettings->inscricao_municipal,
                'codigo_municipio' => $fiscalSettings->codigo_municipio,
            ],
            'tomador' => $tomador,
            'servico' => array_filter([
                'valor_servicos' => (string) request('valor_servicos'),
                'iss_retido' => false,
                'item_lista_servico' => $fiscalSettings->item_lista_servico,
                'discriminacao' => (string) request('discriminacao'),
                'codigo_municipio' => $fiscalSettings->codigo_municipio,
                'aliquota' => $fiscalSettings->aliquota_iss ? (string) $fiscalSettings->aliquota_iss : null,
            ], fn ($value) => $value !== null),
        ];
    }
}
