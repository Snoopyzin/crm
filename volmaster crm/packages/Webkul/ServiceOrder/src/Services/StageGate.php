<?php

namespace Webkul\ServiceOrder\Services;

use Webkul\Fiscal\Models\FiscalDocument;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderAttachment;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Support\Documento;

/**
 * Campos obrigatórios por etapa. O botão "Avançar" só libera quando a lista
 * devolvida por missing() está vazia; quem tem permissão pode forçar o
 * avanço com justificativa (ver StageAdvanceService).
 *
 * Enquanto o piloto estiver desligado (WorkshopSettings 'piloto_ativo'), os
 * bloqueios não valem -- o fluxo antigo continua exatamente como era.
 */
class StageGate
{
    public function __construct(protected OperationService $operations) {}

    public static function active(): bool
    {
        return (bool) WorkshopSettings::get('piloto_ativo', false);
    }

    /**
     * O que falta para a OS sair da etapa atual rumo a $toStage.
     *
     * @return string[]
     */
    public function missing(ServiceOrder $serviceOrder, string $toStage): array
    {
        if (! static::active()) {
            return [];
        }

        $from = $serviceOrder->workshop_status;

        return match ([$from, $toStage]) {
            [ServiceOrder::STAGE_CADASTRO, ServiceOrder::STAGE_ORDEM_SERVICO] => $this->cadastroParaOs($serviceOrder),
            [ServiceOrder::STAGE_ORDEM_SERVICO, ServiceOrder::STAGE_DIAGNOSTICO] => $this->osParaDiagnostico($serviceOrder),
            [ServiceOrder::STAGE_DIAGNOSTICO, ServiceOrder::STAGE_ORCAMENTO] => $this->diagnosticoParaOrcamento($serviceOrder),
            [ServiceOrder::STAGE_ORCAMENTO, ServiceOrder::STAGE_NEGOCIACAO] => $this->orcamentoParaNegociacao($serviceOrder),
            [ServiceOrder::STAGE_NEGOCIACAO, ServiceOrder::STAGE_EM_EXECUCAO] => $this->negociacaoParaExecucao($serviceOrder),
            [ServiceOrder::STAGE_EM_EXECUCAO, ServiceOrder::STAGE_VEICULO_TESTE] => $this->execucaoParaTeste($serviceOrder),
            [ServiceOrder::STAGE_VEICULO_TESTE, ServiceOrder::STAGE_FATURAMENTO] => $this->testeParaFaturamento($serviceOrder),
            [ServiceOrder::STAGE_FATURAMENTO, ServiceOrder::STAGE_POS_VENDA] => $this->faturamentoParaSaida($serviceOrder),
            default => [],
        };
    }

    /**
     * A transição é a saída do veículo (Faturamento -> Pós-Venda)?
     */
    public function isSaida(ServiceOrder $serviceOrder, string $toStage): bool
    {
        return $serviceOrder->workshop_status === ServiceOrder::STAGE_FATURAMENTO && $toStage === ServiceOrder::STAGE_POS_VENDA;
    }

    /**
     * A OS tem nota fiscal? NF autorizada na Focus ou registrada manualmente.
     */
    public function hasNotaFiscal(ServiceOrder $serviceOrder): bool
    {
        if ($serviceOrder->nf_manual_em) {
            return true;
        }

        return FiscalDocument::where('quote_id', $serviceOrder->id)
            ->where('status', FiscalDocument::STATUS_AUTORIZADO)
            ->exists();
    }

    /**
     * Pagamento confirmado (cobrança paga ou confirmação manual) ou acordo
     * assinado?
     */
    public function hasPagamentoOuAcordo(ServiceOrder $serviceOrder): bool
    {
        if ($serviceOrder->pagamento_confirmado_em || $serviceOrder->acordo_assinado_em) {
            return true;
        }

        return $serviceOrder->boletos()->where('status', 'Pago')->exists();
    }

    /**
     * Quanto tempo (minutos) o diagnóstico levou, ou null se não tem início/fim.
     */
    public function diagnosticoMinutos(ServiceOrder $serviceOrder): ?float
    {
        if (! $serviceOrder->diagnostico_inicio_em || ! $serviceOrder->diagnostico_concluido_em) {
            return null;
        }

        return max(0, $serviceOrder->diagnostico_concluido_em->getTimestamp() - $serviceOrder->diagnostico_inicio_em->getTimestamp()) / 60;
    }

    /**
     * O diagnóstico passou do tempo alvo do tipo de queixa?
     */
    public function diagnosticoPassouDoAlvo(ServiceOrder $serviceOrder): bool
    {
        $alvo = $serviceOrder->queixaTipo?->tempoAlvoEfetivoMin();
        $minutos = $this->diagnosticoMinutos($serviceOrder);

        return $alvo && $minutos !== null && $minutos > $alvo;
    }

    protected function cadastroParaOs(ServiceOrder $os): array
    {
        $missing = [];
        $person = $os->person;

        // CPF/CNPJ obrigatório e com os dígitos verificadores corretos (B2).
        $documento = (string) ($person?->cpf_cnpj ?? '');

        if (! preg_replace('/\D/', '', $documento)) {
            $missing[] = 'CPF/CNPJ do cliente';
        } elseif (! Documento::valido($documento)) {
            $missing[] = 'CPF/CNPJ do cliente inválido (confira os dígitos)';
        }

        $hasPhone = collect($person?->contact_numbers ?? [])->contains(fn ($n) => ! empty($n['value']));

        if (! $hasPhone) {
            $missing[] = 'Telefone do cliente';
        }

        // Serviço só de software não tem veículo: placa, km e termo de
        // entrada não se aplicam.
        if ($os->vehicle_id) {
            if (! $os->vehicle?->placa) {
                $missing[] = 'Placa do veículo';
            }

            if (! $os->km_entrada) {
                $missing[] = 'Km de entrada';
            }

            if (! filled($os->combustivel_entrada)) {
                $missing[] = 'Nível de combustível';
            }

            if (! filled(trim((string) $os->itens_entrada))) {
                $missing[] = 'Itens do veículo (descreva, ou escreva "sem itens nem avarias")';
            }

            // Pelo menos N fotos do veículo (padrão 4; a oficina pode mudar).
            $minimoFotos = max(1, (int) WorkshopSettings::get('termo_fotos_minimas', 4));
            $fotos = $os->attachments()->where('tipo', ServiceOrderAttachment::TIPO_TERMO_FOTO)->count();

            if ($fotos < $minimoFotos) {
                $missing[] = 'Termo de entrada: fotos do veículo (mínimo '.$minimoFotos.', há '.$fotos.')';
            }

            // O aceite (assinatura na tela, link do WhatsApp ou marcado na OS) registra também a resposta
            // sim/não da autorização de rodagem: "não" não trava a saída do Cadastro, só o teste de rodagem.
            if (! $os->termo_assinado_em) {
                $missing[] = 'Termo de entrada assinado';
            }
        }

        if (! in_array($os->cor_credito, array_keys(ServiceOrder::CORES_CREDITO), true)) {
            $missing[] = 'Cor de crédito (verde, amarelo ou vermelho)';
        }

        return $missing;
    }

    protected function osParaDiagnostico(ServiceOrder $os): array
    {
        return $os->tecnicoDesignadoId() ? [] : ['Técnico designado'];
    }

    protected function diagnosticoParaOrcamento(ServiceOrder $os): array
    {
        $missing = [];

        if (! trim((string) $os->diagnostico)) {
            $missing[] = 'Laudo do diagnóstico';
        }

        if (! $os->laudoItems()->exists()) {
            $missing[] = 'Laudo com pelo menos 1 evidência (código de falha, medição ou foto)';
        }

        if (! $os->diagnostico_concluido_em) {
            $missing[] = 'Diagnóstico finalizado pelo técnico';
        }

        if ($os->diagnostico_visto_status !== 'aprovado') {
            $missing[] = $os->diagnostico_visto_status === 'devolvido'
                ? 'Diagnóstico devolvido pelo chefe: corrigir e pedir novo visto'
                : 'Visto do chefe de oficina no diagnóstico';
        }

        if (! $os->relacao_pecas_em) {
            $missing[] = 'Relação de peças entregue';
        }

        if ($this->diagnosticoPassouDoAlvo($os) && ! $os->diagnostico_causa_atraso) {
            $missing[] = 'Causa do atraso no diagnóstico (passou do tempo alvo)';
        }

        return $missing;
    }

    protected function negociacaoParaExecucao(ServiceOrder $os): array
    {
        $missing = [];

        if ($os->aprovacao_tipo === 'recusa') {
            $missing[] = 'Cliente recusou o orçamento';
        } elseif (! $os->aprovacao_em || ! in_array($os->aprovacao_tipo, ['total', 'parcial'], true)) {
            $missing[] = 'Aprovação do cliente registrada (meio e hora)';
        }

        if (! trim((string) $os->condicao_pagamento)) {
            $missing[] = 'Forma de pagamento';
        }

        return $missing;
    }

    /**
     * Orçamento -> Negociação: todas as peças precificadas por Peças e o
     * orçamento enviado ao cliente.
     */
    protected function orcamentoParaNegociacao(ServiceOrder $os): array
    {
        $missing = $os->items()
            ->where('tipo', ServiceOrderItem::TIPO_PECA)
            ->whereNull('precificada_em')
            ->get()
            ->map(fn ($item) => 'Peça sem preço definido por Peças: '.$item->name)
            ->all();

        if (! $os->orcamento_enviado_em) {
            $missing[] = 'Orçamento enviado ao cliente';
        }

        return $missing;
    }

    protected function execucaoParaTeste(ServiceOrder $os): array
    {
        $missing = $this->operations->pendingOperations($os)
            ->map(fn ($item) => 'Operação sem início/fim apontado: '.$item->name)
            ->all();

        // Motor e transmissão exigem o checklist de montagem.
        if ($this->isMotorOuTransmissao($os) && ! ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'checklist_montagem')->exists()) {
            $missing[] = 'Checklist de montagem (serviço de motor ou transmissão)';
        }

        return $missing;
    }

    /**
     * Test drive -> Faturamento: autorização de rodagem, condutor habilitado
     * na categoria, km e horário de saída e volta e conferência do chefe.
     */
    protected function testeParaFaturamento(ServiceOrder $os): array
    {
        if (! $os->vehicle_id) {
            return [];
        }

        $missing = [];

        if (! $os->autorizacao_rodagem) {
            $missing[] = 'Autorização de rodagem do cliente';
        }

        $condutor = $os->testeMechanic;

        if (! $condutor) {
            $missing[] = 'Condutor do test drive';
        } elseif (! $this->cnhValida($condutor->cnh_categoria, $os)) {
            $missing[] = 'Condutor sem CNH na categoria exigida (cadastre a categoria da CNH do funcionário)';
        }

        if (! $os->teste_iniciado_em || ! $os->teste_finalizado_em) {
            $missing[] = 'Horário de saída e volta do test drive';
        }

        if ($os->teste_km_saida === null || $os->teste_km_volta === null) {
            $missing[] = 'Km de saída e de volta do test drive';
        }

        if (! $os->conferencia_final_em) {
            $missing[] = 'Conferência final do chefe de oficina';
        }

        return $missing;
    }

    protected function faturamentoParaSaida(ServiceOrder $os): array
    {
        $missing = [];

        if (! $os->checklist_saida_em) {
            $missing[] = 'Checklist de saída com horas reais e próxima revisão';
        }

        // Cliente de frota com faturamento mensal é a única exceção da nota e do pagamento.
        if ($os->isFrotaFaturamentoMensal()) {
            return $missing;
        }

        if (! $this->hasNotaFiscal($os)) {
            $missing[] = 'Nota fiscal emitida';
        }

        if (! $this->hasPagamentoOuAcordo($os)) {
            $missing[] = 'Pagamento confirmado ou acordo assinado';
        }

        return $missing;
    }

    /**
     * Serviço de motor ou transmissão: alguma operação é executada por um
     * técnico dessas especialidades.
     */
    protected function isMotorOuTransmissao(ServiceOrder $os): bool
    {
        foreach ($os->operations()->with('funcionario')->get() as $operacao) {
            if (in_array($operacao->funcionario?->especialidade, ['motor', 'transmissao'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Caminhão exige CNH C, D ou E; os demais veículos, qualquer categoria B ou superior.
     */
    protected function cnhValida(?string $categoria, ServiceOrder $os): bool
    {
        $categoria = strtoupper(trim((string) $categoria));

        if ($categoria === '') {
            return false;
        }

        $aceitas = $os->vehicle?->tipo === 'caminhoes' ? ['C', 'D', 'E'] : ['B', 'C', 'D', 'E'];

        return in_array(substr($categoria, 0, 1), $aceitas, true);
    }
}
