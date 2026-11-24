<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Models\ApiKey;
use Webkul\Admin\Notifications\Common;
use Webkul\Core\Traits\PDFHandler;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderBoleto;
use Webkul\ServiceOrder\Models\ServiceOrderChecklistItem;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderFeedback;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Models\ServiceOrderTimeLog;
use Webkul\ServiceOrder\Repositories\ServiceOrderRepository;
use Webkul\ServiceOrder\Services\Alcada\AlcadaService;
use Webkul\ServiceOrder\Services\AsaasClient;
use Webkul\ServiceOrder\Services\Credito\CreditoCliente;
use Webkul\ServiceOrder\Services\StageGate;
use Webkul\ServiceOrder\Services\ValoresFinanceiros;
use Webkul\ServiceOrder\Services\WorkflowService;
use Webkul\WhatsApp\Models\WhatsAppSettings;
use Webkul\WhatsApp\Services\WhatsAppCloudClient;

/**
 * Every action specific to one stage of the pipeline that isn't plain
 * CRUD on the ServiceOrder itself (that stays in ServiceOrderController).
 * All of them redirect back to the shared service_order::view detail page.
 */
class ServiceOrderStageController extends Controller
{
    use PDFHandler;

    public function __construct(protected ServiceOrderRepository $serviceOrderRepository) {}

    /**
     * Diagnóstico: save the mechanic's findings and who/when it was done.
     *
     * Com o piloto ligado, salvar só grava o texto do laudo: iniciar e
     * finalizar o diagnóstico (com os carimbos e as validações) são botões
     * próprios do bloco "Fluxo da OS".
     */
    public function updateDiagnostico(int $id): RedirectResponse
    {
        $this->validate(request(), [
            'diagnostico' => 'required|string',
            'diagnostico_o_que_fazer' => 'nullable|string|max:5000',
            'diagnostico_mechanic_id' => 'nullable|integer|exists:mechanics,id',
        ]);

        // "O que precisa ser feito": a decisão do diagnóstico (só onde a coluna já existe).
        $oQueFazer = request()->has('diagnostico_o_que_fazer') && Schema::hasColumn('quotes', 'diagnostico_o_que_fazer')
            ? ['diagnostico_o_que_fazer' => request('diagnostico_o_que_fazer') ?: null]
            : [];

        if (StageGate::active()) {
            $this->serviceOrderRepository->update(['diagnostico' => request('diagnostico')] + $oQueFazer, $id);

            session()->flash('success', 'Laudo salvo. Use "Finalizar diagnóstico" quando terminar.');

            return redirect()->route('admin.service_orders.view', $id);
        }

        $serviceOrder = $this->serviceOrderRepository->findOrFail($id);
        $primeiraVez = ! $serviceOrder->diagnostico_concluido_em;

        $this->serviceOrderRepository->update([
            'diagnostico' => request('diagnostico'),
            'diagnostico_mechanic_id' => request('diagnostico_mechanic_id'),
            'diagnostico_concluido_em' => now(),
        ] + $oQueFazer, $id);

        // Fluxo antigo: o primeiro "salvar" já conta como fim do diagnóstico.
        if ($primeiraVez) {
            ServiceOrderEvent::record($serviceOrder, 'diagnostico_fim', ['origem' => 'tela_antiga']);
        }

        session()->flash('success', 'Diagnóstico salvo com sucesso.');

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Diagnóstico: notify the client via WhatsApp that the diagnosis is
     * ready, same graceful-failure pattern as whatsappOrcamento().
     */
    public function whatsappDiagnostico(int $id, WhatsAppCloudClient $client): RedirectResponse
    {
        $serviceOrder = $this->serviceOrderRepository->findOrFail($id);
        $serviceOrder->load(['person', 'vehicle']);

        if (! $serviceOrder->diagnostico) {
            session()->flash('error', 'Salve o diagnóstico antes de enviar.');

            return redirect()->route('admin.service_orders.view', $id);
        }

        $phone = $this->clientWhatsApp($serviceOrder);

        if (! $phone) {
            session()->flash('error', 'O cliente não tem um WhatsApp cadastrado.');

            return redirect()->route('admin.service_orders.view', $id);
        }

        $result = $client->sendTemplate(
            $phone,
            WhatsAppSettings::current()->template_diagnostico,
            [
                $serviceOrder->person->name,
                $serviceOrder->vehicle->placa ?? '-',
                $serviceOrder->diagnostico,
            ],
            $serviceOrder->id
        );

        session()->flash($result['success'] ? 'success' : 'error', $result['success']
            ? 'Diagnóstico enviado por WhatsApp com sucesso.'
            : $result['message']);

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Negociação: record what was discussed with the client (discount
     * fields already exist on the order and are edited from the same form).
     */
    public function updateNegociacao(int $id): RedirectResponse
    {
        ValoresFinanceiros::authorize('negociação');

        $this->validate(request(), [
            'negociacao_observacoes' => 'nullable|string',
            'condicao_pagamento' => 'nullable|string|max:255',
            'item_discounts' => 'nullable|array',
            'item_discounts.*' => 'nullable|numeric|min:0',
            'item_funcionarios' => 'nullable|array',
            'item_funcionarios.*' => 'nullable|integer|exists:mechanics,id',
        ]);

        $serviceOrder = $this->serviceOrderRepository->findOrFail($id);

        // Alçadas: desconto e forma de pagamento acima do limite de quem salva
        // viram pedido de aprovação e nada é gravado até a aprovação.
        try {
            $this->checkAlcadas($serviceOrder);
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first());

            return redirect()->route('admin.service_orders.view', $id);
        }

        // The discount lives on each item (the consultant spreads a general
        // discount across them in the screen, then fine-tunes any line),
        // since each line's net value is what commissions are based on.
        // Capped at the line's gross so no item goes negative.
        foreach ($serviceOrder->items as $item) {
            $discount = (float) (request("item_discounts.{$item->id}") ?? 0);

            if (request()->has("item_funcionarios.{$item->id}")) {
                $item->funcionario_id = request("item_funcionarios.{$item->id}") ?: null;
            }

            $item->discount_percent = 0;
            $item->discount_amount = round(min(max(0, $discount), $item->gross_total), 2);
            $item->total = $item->line_total;
            $item->save();
        }

        // Any older whole-order discount is superseded by the per-item one.
        $this->serviceOrderRepository->update([
            'negociacao_observacoes' => request('negociacao_observacoes'),
            'condicao_pagamento' => request('condicao_pagamento'),
            'discount_percent' => 0,
            'discount_amount' => 0,
        ], $id);

        $this->serviceOrderRepository->recalculateTotals($serviceOrder->fresh());

        // Registra a forma de pagamento quando ela é definida ou muda.
        if (trim((string) request('condicao_pagamento')) !== '' && request('condicao_pagamento') !== $serviceOrder->condicao_pagamento) {
            app(WorkflowService::class)->registerPaymentTerms($this->serviceOrderRepository->findOrFail($id));
        }

        session()->flash('success', 'Negociação salva com sucesso.');

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Confere as alçadas da negociação: percentual de desconto e forma de
     * pagamento a prazo para cliente de crédito amarelo ou vermelho.
     *
     * @throws ValidationException
     */
    protected function checkAlcadas(ServiceOrder $serviceOrder): void
    {
        $alcadas = app(AlcadaService::class);

        $bruto = (float) $serviceOrder->items->sum(fn ($item) => $item->gross_total);
        $novo = 0.0;

        foreach ($serviceOrder->items as $item) {
            $novo += round(min(max(0, (float) (request("item_discounts.{$item->id}") ?? 0)), $item->gross_total), 2);
        }

        $atual = (float) $serviceOrder->items->sum(fn ($item) => (float) $item->discount_amount);

        // Só confere quando o desconto aumenta (salvar de novo o mesmo desconto já aprovado não pede nada).
        if ($bruto > 0 && $novo > $atual + 0.004) {
            $percentual = round($novo / $bruto * 100, 2);

            $alcadas->authorize('desconto', $serviceOrder, $percentual, 'Desconto de '.number_format($percentual, 1, ',', '.').'% no orçamento');
        }

        $condicao = trim((string) request('condicao_pagamento'));

        // B1: vale a cor de crédito do CLIENTE (a análise mais recente ou a sugestão do sistema, a pior das duas
        // quando a sugestão piorou depois); sem histórico do cliente, a cor registrada na OS.
        $corCredito = app(CreditoCliente::class)->corEfetiva($serviceOrder->person_id) ?? $serviceOrder->cor_credito;

        if ($condicao !== '' && $condicao !== (string) $serviceOrder->condicao_pagamento
            && in_array($corCredito, ['amarelo', 'vermelho'], true)
            && preg_match('/dias|prazo|faturad|boleto|parcel|duplicata/iu', $condicao)
            && ! preg_match('/vista|pix|dinheiro|cart[aã]o|d[eé]bito/iu', $condicao)) {
            $alcadas->authorize(
                'condicao_pagamento',
                $serviceOrder,
                $corCredito === 'vermelho' ? 2 : 1,
                'Forma de pagamento a prazo para cliente de crédito '.$corCredito
            );
        }
    }

    /**
     * Checklist: add/toggle/remove an item shown to the client on the Orçamento.
     */
    public function storeChecklistItem(int $id): RedirectResponse
    {
        $this->validate(request(), ['descricao' => 'required|string|max:255']);

        ServiceOrderChecklistItem::create([
            'quote_id' => $id,
            'descricao' => request('descricao'),
        ]);

        return redirect()->route('admin.service_orders.view', $id);
    }

    public function toggleChecklistItem(int $id, int $itemId): RedirectResponse
    {
        $item = ServiceOrderChecklistItem::where('quote_id', $id)->findOrFail($itemId);

        $item->update(['concluido' => ! $item->concluido]);

        return redirect()->route('admin.service_orders.view', $id);
    }

    public function destroyChecklistItem(int $id, int $itemId): RedirectResponse
    {
        ServiceOrderChecklistItem::where('quote_id', $id)->findOrFail($itemId)->delete();

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Orçamento: the mechanic raises (or clears) the orange/red semaphore
     * light on a line item.
     */
    public function updateItemAlerta(int $id, int $itemId): RedirectResponse
    {
        $this->validate(request(), [
            'alerta' => 'nullable|in:Atenção,Crítico',
            'alerta_observacao' => 'nullable|string|max:500',
        ]);

        ServiceOrderItem::where('quote_id', $id)->findOrFail($itemId)->update([
            'alerta' => request('alerta') ?: null,
            'alerta_observacao' => request('alerta_observacao'),
        ]);

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Orçamento: e-mail the current items/checklist/TS as a PDF, mirroring
     * Webkul\Admin\Http\Controllers\Lead\QuoteController::mail().
     */
    public function mailOrcamento(int $id): RedirectResponse
    {
        ValoresFinanceiros::authorize('enviar orçamento');

        $serviceOrder = $this->serviceOrderRepository->findOrFail($id);
        $serviceOrder->load(['person', 'vehicle', 'items', 'checklistItems']);

        $to = $serviceOrder->person?->emails[0]['value'] ?? null;

        if (! $to) {
            session()->flash('error', 'O cliente não tem um e-mail cadastrado.');

            return redirect()->route('admin.service_orders.view', $id);
        }

        $pdfContent = $this->renderOrcamentoPdfContent($serviceOrder);

        try {
            Mail::send(new Common([
                'to' => $to,
                'subject' => 'Orçamento #'.$serviceOrder->id.' - '.$serviceOrder->identificacao(),
                'body' => 'Segue em anexo o orçamento do serviço para o veículo '.($serviceOrder->vehicle->placa ?? '').'.',
                'attachments' => [[
                    'content' => $pdfContent,
                    'name' => 'Orcamento_'.$serviceOrder->id.'.pdf',
                    'mime' => 'application/pdf',
                ]],
            ]));

            app(WorkflowService::class)->registerBudgetSent($serviceOrder, 'E-mail');

            session()->flash('success', 'Orçamento enviado por e-mail com sucesso.');
        } catch (\Exception $exception) {
            session()->flash('error', 'Falha ao enviar o e-mail: '.$exception->getMessage());
        }

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Render the Orçamento (same layout as the OS print view) and return
     * the raw PDF bytes -- mirrors the private helper in
     * Webkul\Admin\Http\Controllers\Lead\QuoteController::renderQuotePdfContent().
     */
    private function renderOrcamentoPdfContent(ServiceOrder $serviceOrder): string
    {
        $html = view('service_order::print', compact('serviceOrder'))->render();

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');

        return Pdf::loadHTML($this->adjustArabicAndPersianContent($html))
            ->setPaper('A4', 'portrait')
            ->set_option('defaultFont', 'Courier')
            ->output();
    }

    /**
     * Orçamento: send the summary via the WhatsApp Cloud API (approved
     * template) -- fails gracefully with a flash message until the tenant
     * finishes the Meta setup in Configurações > WhatsApp.
     */
    public function whatsappOrcamento(int $id, WhatsAppCloudClient $client): RedirectResponse
    {
        ValoresFinanceiros::authorize('enviar orçamento');

        $serviceOrder = $this->serviceOrderRepository->findOrFail($id);
        $serviceOrder->load(['person', 'vehicle']);

        $phone = $this->clientWhatsApp($serviceOrder);

        if (! $phone) {
            session()->flash('error', 'O cliente não tem um WhatsApp cadastrado.');

            return redirect()->route('admin.service_orders.view', $id);
        }

        $result = $client->sendTemplate(
            $phone,
            WhatsAppSettings::current()->template_orcamento,
            [
                $serviceOrder->person->name,
                $serviceOrder->vehicle->placa ?? '-',
                core()->formatBasePrice($serviceOrder->grand_total),
                $serviceOrder->tempo_servico ?? '-',
            ],
            $serviceOrder->id
        );

        if ($result['success']) {
            app(WorkflowService::class)->registerBudgetSent($serviceOrder, 'WhatsApp');
        }

        session()->flash($result['success'] ? 'success' : 'error', $result['success']
            ? 'Orçamento enviado por WhatsApp com sucesso.'
            : $result['message']);

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Em Execução: what the mechanic is working on right now (e.g.
     * "Trocando o cabeçote") -- free text, shown next to the timer.
     */
    public function updateExecucaoTarefa(int $id): RedirectResponse
    {
        $this->validate(request(), ['execucao_tarefa_atual' => 'nullable|string|max:255']);

        $this->serviceOrderRepository->update([
            'execucao_tarefa_atual' => request('execucao_tarefa_atual'),
        ], $id);

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Em Execução: start/pause/resume/finish the timer. Pausing always
     * requires a justificativa (the whole point of this stage).
     */
    public function timerStart(int $id): RedirectResponse
    {
        return $this->logTimerAction($id, ServiceOrderTimeLog::ACTION_INICIADO);
    }

    public function timerPause(int $id): RedirectResponse
    {
        $this->validate(request(), ['justificativa' => 'required|string|max:500']);

        return $this->logTimerAction($id, ServiceOrderTimeLog::ACTION_PAUSADO, request('justificativa'));
    }

    public function timerResume(int $id): RedirectResponse
    {
        return $this->logTimerAction($id, ServiceOrderTimeLog::ACTION_RETOMADO);
    }

    public function timerFinish(int $id): RedirectResponse
    {
        return $this->logTimerAction($id, ServiceOrderTimeLog::ACTION_CONCLUIDO);
    }

    protected function logTimerAction(int $id, string $action, ?string $justificativa = null): RedirectResponse
    {
        $serviceOrder = $this->serviceOrderRepository->findOrFail($id);

        ServiceOrderTimeLog::create([
            'quote_id' => $serviceOrder->id,
            'mechanic_id' => $serviceOrder->mechanic_id,
            'action' => $action,
            'justificativa' => $justificativa,
        ]);

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Veículo em Teste: mark the test drive as started/finished with notes.
     */
    public function startTeste(int $id): RedirectResponse
    {
        $serviceOrder = $this->serviceOrderRepository->update([
            'teste_mechanic_id' => request('teste_mechanic_id'),
            'teste_iniciado_em' => now(),
        ], $id);

        if (request()->filled('teste_km_saida')) {
            $serviceOrder->forceFill(['teste_km_saida' => max(0, (int) request('teste_km_saida'))])->save();
        }

        ServiceOrderEvent::record($serviceOrder, 'teste_inicio', [
            'condutor_mechanic_id' => request('teste_mechanic_id'),
            'km_saida' => request('teste_km_saida'),
        ]);

        return redirect()->route('admin.service_orders.view', $id);
    }

    public function finishTeste(int $id): RedirectResponse
    {
        $this->validate(request(), [
            'teste_observacoes' => 'nullable|string',
            'teste_km_volta' => 'nullable|integer|min:0',
            'teste_resultado' => 'nullable|in:aprovado,reprovado',
        ]);

        $serviceOrder = $this->serviceOrderRepository->update([
            'teste_finalizado_em' => now(),
            'teste_observacoes' => request('teste_observacoes'),
        ], $id);

        $serviceOrder->forceFill(array_filter([
            'teste_km_volta' => request()->filled('teste_km_volta') ? (int) request('teste_km_volta') : null,
            'teste_resultado' => request('teste_resultado'),
        ], fn ($value) => $value !== null))->save();

        ServiceOrderEvent::record($serviceOrder, 'teste_fim', [
            'km_volta' => request('teste_km_volta'),
            'resultado' => request('teste_resultado'),
        ]);

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Faturamento: manual boleto tracking, for shops that don't want to
     * (or can't yet) use the Asaas integration below.
     */
    public function storeBoleto(int $id): RedirectResponse
    {
        ValoresFinanceiros::authorize('cobrança');

        $this->validate(request(), [
            'valor' => 'required|numeric|min:0',
            'vencimento' => 'nullable|date',
            'link_pagamento' => 'nullable|string|max:255',
        ]);

        ServiceOrderBoleto::create([
            'quote_id' => $id,
            'valor' => request('valor'),
            'vencimento' => request('vencimento'),
            'link_pagamento' => request('link_pagamento'),
        ]);

        ServiceOrderEvent::record($id, 'boleto_ou_cobranca_gerada', ['origem' => 'manual']);

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Faturamento: generate a real charge via Asaas (customer picks boleto,
     * Pix, or card on Asaas's own hosted invoice page) instead of tracking
     * a boleto by hand.
     */
    public function storeBoletoViaAsaas(int $id, AsaasClient $client): RedirectResponse
    {
        ValoresFinanceiros::authorize('cobrança');

        $this->validate(request(), [
            'valor' => 'required|numeric|min:0',
            'vencimento' => 'nullable|date',
        ]);

        if (! ApiKey::asaasToken()) {
            session()->flash('error', 'Asaas não configurado ainda. Vá em Configurações → Chaves de API e cadastre uma com o tipo "Asaas".');

            return redirect()->route('admin.service_orders.view', $id);
        }

        $serviceOrder = $this->serviceOrderRepository->findOrFail($id);
        $serviceOrder->load('person');

        if (! preg_replace('/\D/', '', $serviceOrder->person->cpf_cnpj ?? '')) {
            session()->flash('error', 'O cliente dessa OS não tem CPF/CNPJ cadastrado -- a Asaas exige isso pra gerar a cobrança.');

            return redirect()->route('admin.service_orders.view', $id);
        }

        try {
            $client->createCharge($serviceOrder, (float) request('valor'), request('vencimento'));

            ServiceOrderEvent::record($serviceOrder, 'boleto_ou_cobranca_gerada', ['origem' => 'asaas']);

            session()->flash('success', 'Cobrança gerada com sucesso.');
        } catch (\Throwable $exception) {
            session()->flash('error', 'Falha ao gerar cobrança via Asaas: '.$exception->getMessage());
        }

        return redirect()->route('admin.service_orders.view', $id);
    }

    public function updateBoletoStatus(int $id, int $boletoId): RedirectResponse
    {
        ValoresFinanceiros::authorize('cobrança');

        $this->validate(request(), ['status' => 'required|in:Pendente,Pago,Cancelado']);

        ServiceOrderBoleto::where('quote_id', $id)->findOrFail($boletoId)->update([
            'status' => request('status'),
        ]);

        if (request('status') === 'Pago') {
            app(WorkflowService::class)->confirmPayment($this->serviceOrderRepository->findOrFail($id), 'cobranca');
        }

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * Pós-Venda: send the satisfaction survey via WhatsApp with a link to
     * the public feedback page, and show the vehicle's maintenance history.
     */
    public function sendPosVenda(int $id, WhatsAppCloudClient $client): RedirectResponse
    {
        $serviceOrder = $this->serviceOrderRepository->findOrFail($id);
        $serviceOrder->load('person');

        $phone = $this->clientWhatsApp($serviceOrder);

        $feedback = $serviceOrder->feedback ?? ServiceOrderFeedback::create(['quote_id' => $serviceOrder->id]);

        if (! $phone) {
            session()->flash('error', 'O cliente não tem um WhatsApp cadastrado.');

            return redirect()->route('admin.service_orders.view', $id);
        }

        $result = $client->sendTemplate(
            $phone,
            WhatsAppSettings::current()->template_pos_venda,
            [$serviceOrder->person->name, route('service_orders.feedback.show', $feedback->token)],
            $serviceOrder->id
        );

        if ($result['success']) {
            $feedback->update(['enviado_em' => now()]);

            ServiceOrderEvent::record($serviceOrder, 'posvenda_contato', ['canal' => 'WhatsApp']);
        }

        session()->flash($result['success'] ? 'success' : 'error', $result['success']
            ? 'Pesquisa de satisfação enviada com sucesso.'
            : $result['message']);

        return redirect()->route('admin.service_orders.view', $id);
    }

    /**
     * The client's number for WhatsApp: the one labelled WhatsApp if any,
     * otherwise the first one registered.
     */
    protected function clientWhatsApp($serviceOrder): ?string
    {
        $numbers = collect($serviceOrder->person?->contact_numbers ?? [])->filter(fn ($number) => ! empty($number['value']));

        return ($numbers->first(fn ($number) => stripos($number['label'] ?? '', 'whats') !== false) ?? $numbers->first())['value'] ?? null;
    }
}
