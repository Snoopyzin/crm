<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Repositories\ServiceOrderRepository;
use Webkul\ServiceOrder\Services\OperationService;
use Webkul\ServiceOrder\Services\StageGate;
use Webkul\ServiceOrder\Services\TermoEntrada;
use Webkul\ServiceOrder\Services\ValoresFinanceiros;
use Webkul\ServiceOrder\Services\WorkflowActions;
use Webkul\ServiceOrder\Services\WorkflowService;

/**
 * Ações dos passos do fluxo na tela da OS (termo, crédito, designação,
 * diagnóstico, laudo, visto, relação de peças, aprovação, NF, pagamento e
 * operações). A regra de cada passo fica em WorkflowService/OperationService.
 */
class WorkflowController extends Controller
{
    public function __construct(
        protected ServiceOrderRepository $serviceOrderRepository,
        protected WorkflowService $workflow,
        protected OperationService $operations,
    ) {}

    public function saveTermo(int $id): RedirectResponse
    {
        $os = $this->order($id);

        $this->validate(request(), [
            'km_entrada' => 'required|integer|min:0|max:9999999',
            'combustivel_entrada' => 'required|in:'.implode(',', ServiceOrder::COMBUSTIVEIS),
            'itens_entrada' => 'nullable|string|max:2000',
            'autorizacao_rodagem' => 'nullable|boolean',
            'assinado' => 'nullable|boolean',
            'fotos' => 'nullable|array|max:12',
            'fotos.*' => 'image|max:8192',
        ], [
            'km_entrada.required' => 'Informe o km de entrada.',
            'combustivel_entrada.required' => 'Informe o nível de combustível.',
            'fotos.*.image' => 'As fotos do termo precisam ser imagens (JPG ou PNG) de até 8 MB.',
        ]);

        return $this->run($id, function () use ($os) {
            $this->workflow->saveTermo($os, request()->only(['km_entrada', 'combustivel_entrada', 'itens_entrada']) + [
                'autorizacao_rodagem' => request()->boolean('autorizacao_rodagem'),
                'assinado' => request()->boolean('assinado'),
            ], request()->file('fotos', []));
        }, 'Termo de entrada salvo.');
    }

    /**
     * Gera o link do termo para o cliente aceitar pelo WhatsApp (ou assinar na tela do tablet).
     */
    public function termoLink(int $id, TermoEntrada $termos): RedirectResponse
    {
        $os = $this->order($id);

        if (! TermoEntrada::disponivel() || ! $os->vehicle_id) {
            session()->flash('error', 'O aceite pelo link só vale para OS com veículo (e depois de atualizar o sistema).');

            return redirect()->route('admin.service_orders.view', $id);
        }

        session()->flash('termo_link', $termos->gerarLink($os, auth()->guard('user')->user()));
        session()->flash('success', 'Link do termo gerado. Envie ao cliente pelo WhatsApp ou abra no tablet para ele assinar.');

        return redirect()->route('admin.service_orders.view', $id);
    }

    public function saveCorCredito(int $id): RedirectResponse
    {
        ValoresFinanceiros::authorize('cor de crédito');

        // B1: quem registra a análise é o Financeiro, o Gerente ou o Proprietário (o Consultor só pede).
        abort_unless(bouncer()->hasPermission('credito_cliente.registrar') || ! StageGate::active(), 403, 'Só o Financeiro, o Gerente e o Proprietário registram a cor de crédito.');

        $os = $this->order($id);

        $this->validate(request(), [
            'cor_credito' => 'required|in:verde,amarelo,vermelho',
            'observacao' => 'nullable|string|max:1000',
            'justificativa' => 'nullable|string|max:1000',
        ]);

        return $this->run($id, fn () => $this->workflow->setCorCredito($os, request('cor_credito'), request('observacao'), request('justificativa')), 'Cor de crédito registrada.');
    }

    public function designate(int $id): RedirectResponse
    {
        $os = $this->order($id);

        $this->validate(request(), ['mechanic_id' => 'required|integer|exists:mechanics,id']);

        return $this->run($id, fn () => $this->workflow->designateTechnician($os, (int) request('mechanic_id')), 'Técnico designado.');
    }

    public function saveLaudo(int $id): RedirectResponse
    {
        $os = $this->order($id);

        $this->validate(request(), [
            'diagnostico' => 'nullable|string|max:5000',
            'diagnostico_o_que_fazer' => 'nullable|string|max:5000',
            'queixa_tipo_id' => 'nullable|integer|exists:diagnostico_queixa_tipos,id',
        ]);

        return $this->run($id, function () use ($os) {
            $this->workflow->saveLaudo($os, request('diagnostico'), request()->has('diagnostico_o_que_fazer') ? (string) request('diagnostico_o_que_fazer') : null);

            if (! $os->diagnostico_inicio_em && request('queixa_tipo_id')) {
                $os->forceFill(['queixa_tipo_id' => request('queixa_tipo_id')])->save();
            }
        }, 'Laudo salvo.');
    }

    public function startDiagnostico(int $id): RedirectResponse
    {
        $os = $this->order($id);

        $this->validate(request(), ['queixa_tipo_id' => 'nullable|integer|exists:diagnostico_queixa_tipos,id']);

        return $this->run($id, fn () => $this->workflow->startDiagnostico($os, request('queixa_tipo_id') ? (int) request('queixa_tipo_id') : $os->queixa_tipo_id), 'Diagnóstico iniciado.');
    }

    public function finishDiagnostico(int $id): RedirectResponse
    {
        $os = $this->order($id);

        $this->validate(request(), ['causa_atraso' => 'nullable|string|max:60', 'observacao' => 'nullable|string|max:500']);

        return $this->run($id, fn () => $this->workflow->finishDiagnostico($os, request('causa_atraso'), request('observacao')), 'Diagnóstico finalizado.');
    }

    /**
     * O chefe de oficina informa ou corrige os horários de início e conclusão do diagnóstico.
     */
    public function diagnosticoHorarios(int $id): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('service_orders.auditar'), 403);

        $os = $this->order($id);

        $this->validate(request(), [
            'diagnostico_inicio_em' => 'nullable|date',
            'diagnostico_fim_em' => 'nullable|date',
            'justificativa' => 'nullable|string|max:500',
        ]);

        return $this->run($id, fn () => $this->workflow->informarHorariosDiagnostico(
            $os,
            request('diagnostico_inicio_em'),
            request('diagnostico_fim_em'),
            request('justificativa')
        ), 'Horários do diagnóstico registrados.');
    }

    /**
     * Fotos e vídeos do diagnóstico (até 20 fotos e 3 vídeos por envio, como no Cadastro).
     */
    public function addDiagnosticoMidia(int $id): RedirectResponse
    {
        $os = $this->order($id);

        $this->validate(request(), [
            'fotos' => 'nullable|array|max:20',
            'fotos.*' => 'file|mimes:jpg,jpeg,png,webp,heic,heif|max:10240',
            'videos' => 'nullable|array|max:3',
            'videos.*' => 'file|mimes:mp4,mov,webm,3gp,m4v,avi,mkv|max:102400',
        ], [
            'fotos.max' => 'Envie no máximo 20 fotos por vez.',
            'fotos.*.mimes' => 'Cada foto precisa ser JPG, PNG, WEBP ou HEIC.',
            'fotos.*.max' => 'Cada foto pode ter até 10 MB.',
            'videos.max' => 'Envie no máximo 3 vídeos por vez.',
            'videos.*.mimes' => 'Cada vídeo precisa ser MP4, MOV, WEBM, 3GP, M4V, AVI ou MKV.',
            'videos.*.max' => 'Cada vídeo pode ter até 100 MB.',
        ]);

        $fotos = array_values(array_filter((array) request()->file('fotos'), fn ($arquivo) => $arquivo?->isValid()));
        $videos = array_values(array_filter((array) request()->file('videos'), fn ($arquivo) => $arquivo?->isValid()));

        if (! $fotos && ! $videos) {
            session()->flash('error', 'Escolha ao menos uma foto ou um vídeo.');

            return redirect()->route('admin.service_orders.view', $id);
        }

        return $this->run($id, fn () => $this->workflow->addDiagnosticoMidia($os, $fotos, $videos), 'Fotos e vídeos do diagnóstico enviados.');
    }

    public function addEvidence(int $id): RedirectResponse
    {
        $os = $this->order($id);

        $this->validate(request(), [
            'tipo' => 'required|in:codigo,medicao,foto',
            'descricao' => 'nullable|string|max:255',
            'valor' => 'nullable|string|max:120',
            'foto' => 'nullable|image|max:8192',
        ]);

        return $this->run($id, fn () => $this->workflow->addEvidence($os, request('tipo'), request('descricao'), request('valor'), request()->file('foto')), 'Evidência adicionada ao laudo.');
    }

    public function audit(int $id): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('service_orders.auditar'), 403);

        $os = $this->order($id);

        $this->validate(request(), ['status' => 'required|in:aprovado,devolvido', 'observacao' => 'nullable|string|max:500']);

        return $this->run($id, fn () => $this->workflow->auditDiagnostico($os, request('status'), request('observacao')),
            request('status') === 'aprovado' ? 'Diagnóstico aprovado.' : 'Diagnóstico devolvido ao técnico.');
    }

    public function deliverParts(int $id): RedirectResponse
    {
        $os = $this->order($id);

        return $this->run($id, fn () => $this->workflow->deliverParts($os, request()->boolean('sem_pecas')), 'Relação de peças registrada.');
    }

    public function budgetSent(int $id): RedirectResponse
    {
        $os = $this->order($id);

        $this->validate(request(), ['canal' => 'required|in:WhatsApp,E-mail,Presencial,Outro']);

        return $this->run($id, fn () => $this->workflow->registerBudgetSent($os, request('canal')), 'Envio do orçamento registrado.');
    }

    public function approval(int $id): RedirectResponse
    {
        $os = $this->order($id);

        $this->validate(request(), [
            'tipo' => 'required|in:total,parcial,recusa',
            'meio' => 'required|string|max:20',
            'motivo_recusa' => 'nullable|string|max:255',
        ]);

        return $this->run($id, fn () => $this->workflow->registerApproval($os, request('tipo'), request('meio'), request('motivo_recusa')), 'Resposta do cliente registrada.');
    }

    public function registerNf(int $id): RedirectResponse
    {
        ValoresFinanceiros::authorize('nota fiscal');

        $os = $this->order($id);

        $this->validate(request(), ['numero' => 'required|string|max:60']);

        return $this->run($id, fn () => $this->workflow->registerNf($os, request('numero')), 'Nota fiscal registrada.');
    }

    public function confirmPayment(int $id): RedirectResponse
    {
        ValoresFinanceiros::authorize('pagamento');

        $os = $this->order($id);

        return $this->run($id, fn () => $this->workflow->confirmPayment($os), 'Pagamento confirmado.');
    }

    public function signAgreement(int $id): RedirectResponse
    {
        ValoresFinanceiros::authorize('acordo de pagamento');

        $os = $this->order($id);

        return $this->run($id, fn () => $this->workflow->signAgreement($os), 'Acordo de pagamento registrado.');
    }

    /**
     * Apontamento de uma operação pela tela da OS: iniciar, pausar,
     * retomar ou concluir.
     */
    public function operation(int $id, int $itemId, string $acao): RedirectResponse
    {
        $os = $this->order($id);
        $item = ServiceOrderItem::where('quote_id', $os->id)->where('tipo', ServiceOrderItem::TIPO_SERVICO)->findOrFail($itemId);

        return $this->run($id, function () use ($item, $acao) {
            match ($acao) {
                'iniciar' => $this->operations->start($item),
                'pausar' => $this->operations->pause($item, (string) request('motivo')),
                'retomar' => $this->operations->resume($item),
                'concluir' => $this->operations->finish($item, request('motivo_atraso'), request('observacao')),
                default => abort(404),
            };
        }, 'Operação atualizada.');
    }

    /**
     * O chefe confirma o motivo do atraso de uma operação concluída acima
     * de 120% do tempo padrão.
     */
    public function confirmDelay(int $id, int $itemId): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('service_orders.auditar'), 403);

        $os = $this->order($id);
        $item = ServiceOrderItem::where('quote_id', $os->id)->findOrFail($itemId);

        ServiceOrderEvent::record($os, 'operacao_atraso_confirmado', ['item_id' => $item->id, 'operacao' => $item->name]);

        session()->flash('success', 'Atraso da operação confirmado.');

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Passos simples do fluxo (recepção, atualização ao cliente, precificação,
     * conferências, checklist de saída, pós-venda, reclamação, retorno...).
     * A regra de cada um fica em WorkflowActions.
     */
    public function action(int $id, string $acao, WorkflowActions $actions): RedirectResponse
    {
        $os = $this->order($id);
        $definition = $actions->definitions()[$acao] ?? null;

        abort_unless($definition, 404);

        if (! empty($definition['valores'])) {
            ValoresFinanceiros::authorize($definition['label']);
        }

        if (! empty($definition['auditar'])) {
            abort_unless(bouncer()->hasPermission('service_orders.auditar'), 403);
        }

        $dados = ! empty($definition['regras']) ? $this->validate(request(), $definition['regras']) : [];

        try {
            session()->flash('success', $actions->perform($os, $acao, $dados));
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first());
        }

        // Abrir retorno leva direto para a OS de retorno recém-criada.
        return redirect()->route('admin.service_orders.view', session()->pull('retorno_id') ?: $id);
    }

    /**
     * Busca a OS respeitando a visibilidade do usuário (igual à tela da OS).
     */
    protected function order(int $id): ServiceOrder
    {
        $os = $this->serviceOrderRepository->findOrFail($id);

        $authorizedUserIds = bouncer()->getAuthorizedUserIds();

        if ($authorizedUserIds !== null) {
            $mechanicId = auth()->guard('user')->user()?->mechanic_id;

            abort_unless(
                in_array($os->user_id, $authorizedUserIds) || ($mechanicId && $os->mechanic_id == $mechanicId),
                403
            );
        }

        return $os;
    }

    /**
     * Executa a ação e volta para a OS com a mensagem de sucesso, ou com o
     * erro de validação da regra de negócio.
     */
    protected function run(int $id, callable $action, string $success): RedirectResponse
    {
        try {
            $action();

            session()->flash('success', $success);
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first());
        }

        return redirect()->route('admin.service_orders.view', $id);
    }
}
