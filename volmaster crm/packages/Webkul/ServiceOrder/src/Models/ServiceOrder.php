<?php

namespace Webkul\ServiceOrder\Models;

use Carbon\Carbon;
use Webkul\Mechanic\Models\MechanicProxy;
use Webkul\Quote\Models\Quote;
use Webkul\ServiceOrder\Services\PrevisaoOs;
use Webkul\Vehicle\Models\VehicleProxy;

class ServiceOrder extends Quote
{
    /**
     * The 9-stage workshop pipeline, in order. Stored in the same
     * `workshop_status` column the model has always used -- only the
     * vocabulary changed (see the 2026_09_01_000000 backfill migration for
     * the mapping from the old 6-value vocabulary).
     */
    /**
     * "Por qual meio você chegou à nossa empresa?" (Marketing report).
     */
    const ORIGENS = ['Instagram', 'Facebook', 'Google', 'TikTok', 'Outdoor Rodovia', 'Indicação', 'Outros'];

    const STAGE_CADASTRO = 'Cadastro';

    const STAGE_DIAGNOSTICO = 'Diagnóstico';

    const STAGE_ORDEM_SERVICO = 'Ordem de Serviço';

    const STAGE_ORCAMENTO = 'Orçamento';

    const STAGE_NEGOCIACAO = 'Negociação';

    const STAGE_EM_EXECUCAO = 'Em Execução';

    const STAGE_VEICULO_TESTE = 'Veículo em Teste';

    const STAGE_FATURAMENTO = 'Faturamento';

    const STAGE_POS_VENDA = 'Pós-Venda';

    /**
     * Terminal states, reachable from any stage, outside the forward flow.
     */
    const STAGE_CONCLUIDA = 'Concluída';

    const STAGE_CANCELADA = 'Cancelada';

    /**
     * Kept as aliases so any old code/tests referencing STATUS_* still resolve.
     */
    const STATUS_ABERTA = self::STAGE_ORDEM_SERVICO;

    const STATUS_AUTORIZADO = self::STAGE_NEGOCIACAO;

    const STATUS_EM_ANDAMENTO = self::STAGE_EM_EXECUCAO;

    const STATUS_AGUARDANDO_PECAS = self::STAGE_EM_EXECUCAO;

    const STATUS_CONCLUIDA = self::STAGE_CONCLUIDA;

    const STATUS_CANCELADA = self::STAGE_CANCELADA;

    protected $table = 'quotes';

    protected $fillable = [
        'subject',
        'description',
        'billing_address',
        'shipping_address',
        'discount_percent',
        'discount_amount',
        'tax_amount',
        'adjustment_amount',
        'sub_total',
        'grand_total',
        'expired_at',
        'user_id',
        'person_id',
        'vehicle_id',
        'mechanic_id',
        'workshop_status',
        'data_previsao',
        'hora_previsao',
        'data_conclusao',
        'tempo_servico',
        'diagnostico',
        'diagnostico_mechanic_id',
        'diagnostico_concluido_em',
        'negociacao_observacoes',
        'condicao_pagamento',
        'origem_cliente',
        'execucao_tarefa_atual',
        'teste_mechanic_id',
        'teste_iniciado_em',
        'teste_finalizado_em',
        'teste_observacoes',
        'descricao_entrada',
        'relato_motorista',
        'defeito_relatado',
        'diagnostico_o_que_fazer',
    ];

    protected $casts = [
        'diagnostico_concluido_em' => 'datetime',
        'teste_iniciado_em' => 'datetime',
        'teste_finalizado_em' => 'datetime',
        'faturado_em' => 'datetime',
        'autorizacao_rodagem' => 'boolean',
        'termo_assinado_em' => 'datetime',
        'cor_credito_em' => 'datetime',
        'diagnostico_inicio_em' => 'datetime',
        'diagnostico_visto_em' => 'datetime',
        'relacao_pecas_em' => 'datetime',
        'orcamento_enviado_em' => 'datetime',
        'aprovacao_em' => 'datetime',
        'execucao_liberada_em' => 'datetime',
        'nf_manual_em' => 'datetime',
        'pagamento_confirmado_em' => 'datetime',
        'acordo_assinado_em' => 'datetime',
        'saida_em' => 'datetime',
        'prazo_prometido_em' => 'datetime',
        'ultima_atualizacao_cliente_em' => 'datetime',
        'conferencia_final_em' => 'datetime',
        'pecas_conferidas_em' => 'datetime',
        'checklist_saida_em' => 'datetime',
        'enviada_financeiro_em' => 'datetime',
        'chamado_autorizado_em' => 'datetime',
        'pre_diagnostico_em' => 'datetime',
        'proxima_revisao_data' => 'date',
    ];

    public const CORES_CREDITO = ['verde' => 'Verde', 'amarelo' => 'Amarelo', 'vermelho' => 'Vermelho'];

    public const COMBUSTIVEIS = ['Reserva', '1/4', '1/2', '3/4', 'Cheio'];

    /**
     * The forward pipeline, in order. STAGE_CONCLUIDA/STAGE_CANCELADA are
     * terminal states reachable from any stage, not part of this sequence.
     */
    public static function stagesFlow(): array
    {
        return [
            self::STAGE_CADASTRO,
            self::STAGE_ORDEM_SERVICO,
            self::STAGE_DIAGNOSTICO,
            self::STAGE_ORCAMENTO,
            self::STAGE_NEGOCIACAO,
            self::STAGE_EM_EXECUCAO,
            self::STAGE_VEICULO_TESTE,
            self::STAGE_FATURAMENTO,
            self::STAGE_POS_VENDA,
        ];
    }

    /**
     * The stage right after this order's current one, or null if this is the
     * last stage of the flow (or the order is in a terminal state).
     *
     * Concluída isn't part of stagesFlow() (it's a terminal state, not a
     * pipeline step), but it IS the one valid "next stage" once Pós-Venda is
     * reached -- returning it here is what makes the existing "Avançar"
     * button on the progress bar able to close out the order at all instead
     * of just disappearing with no way forward.
     */
    public function nextStage(): ?string
    {
        if ($this->workshop_status === self::STAGE_POS_VENDA) {
            return self::STAGE_CONCLUIDA;
        }

        $flow = self::stagesFlow();

        $currentIndex = array_search($this->workshop_status, $flow, true);

        if ($currentIndex === false) {
            return null;
        }

        return $flow[$currentIndex + 1] ?? null;
    }

    /**
     * An order can move forward one stage at a time (Pós-Venda -> Concluída
     * included), or into Cancelada from anywhere.
     */
    public function canAdvanceTo(string $stage): bool
    {
        if ($stage === self::STAGE_CANCELADA) {
            return $this->workshop_status !== self::STAGE_CANCELADA;
        }

        return $stage === $this->nextStage();
    }

    /**
     * The 6-step version of the pipeline shown to the customer on the
     * public tracking page -- the 9 internal stages are too technical
     * (Negociação, Faturamento...) for that audience, so several collapse
     * into one step there.
     */
    public static function customerTrackingSteps(): array
    {
        return [
            'Recebido',
            'Em Diagnóstico',
            'Orçamento Enviado',
            'Em Execução',
            'Em Teste',
            'Pronto para Retirada',
        ];
    }

    /**
     * Which of the 6 customer-facing steps this order is currently at.
     */
    public function customerTrackingStepIndex(): int
    {
        return match ($this->workshop_status) {
            self::STAGE_CADASTRO, self::STAGE_ORDEM_SERVICO => 0,
            self::STAGE_DIAGNOSTICO => 1,
            self::STAGE_ORCAMENTO, self::STAGE_NEGOCIACAO => 2,
            self::STAGE_EM_EXECUCAO => 3,
            self::STAGE_VEICULO_TESTE => 4,
            self::STAGE_FATURAMENTO, self::STAGE_POS_VENDA, self::STAGE_CONCLUIDA => 5,
            default => 0,
        };
    }

    /**
     * Scope every query through this model to only the Quote rows that represent
     * a workshop Service Order: a vehicle attached, or (software-only services,
     * no vehicle) a workshop stage. Plain CRM quotes have neither.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('serviceOrder', function ($query) {
            $query->where(fn ($query) => $query->whereNotNull('vehicle_id')->orWhereNotNull('workshop_status'));
        });
    }

    public function vehicle()
    {
        return $this->belongsTo(VehicleProxy::modelClass());
    }

    public function mechanic()
    {
        return $this->belongsTo(MechanicProxy::modelClass());
    }

    public function items()
    {
        return $this->hasMany(ServiceOrderItem::class, 'quote_id');
    }

    public function diagnosticoMechanic()
    {
        return $this->belongsTo(MechanicProxy::modelClass(), 'diagnostico_mechanic_id');
    }

    public function testeMechanic()
    {
        return $this->belongsTo(MechanicProxy::modelClass(), 'teste_mechanic_id');
    }

    public function checklistItems()
    {
        return $this->hasMany(ServiceOrderChecklistItem::class, 'quote_id');
    }

    /**
     * Cronômetro geral da OS (legado). As operações individuais têm o
     * próprio registro em operationLogs() e não entram aqui.
     */
    public function timeLogs()
    {
        return $this->hasMany(ServiceOrderTimeLog::class, 'quote_id')->whereNull('item_id')->orderBy('created_at');
    }

    public function operationLogs()
    {
        return $this->hasMany(ServiceOrderTimeLog::class, 'quote_id')->whereNotNull('item_id')->orderBy('created_at')->orderBy('id');
    }

    public function events()
    {
        return $this->hasMany(ServiceOrderEvent::class, 'service_order_id')->orderBy('ocorrido_em')->orderBy('id');
    }

    public function attachments()
    {
        return $this->hasMany(ServiceOrderAttachment::class, 'quote_id');
    }

    /**
     * "Como chegou à empresa" já registrado para o cliente (a primeira origem
     * que ele teve). Só o Novo Cadastro pergunta; as OS seguintes herdam.
     */
    public static function origemDoCliente(?int $personId): ?string
    {
        if (! $personId) {
            return null;
        }

        return static::where('person_id', $personId)->whereNotNull('origem_cliente')->orderBy('id')->value('origem_cliente');
    }

    /**
     * Previsão de conclusão para exibir: "17/11/2026" ou, para o veículo que
     * entra e sai no mesmo dia, "17/11/2026 às 15:30". Sem previsão: null.
     */
    public function previsaoTexto(): ?string
    {
        return PrevisaoOs::texto($this->data_previsao, $this->getAttribute('hora_previsao'));
    }

    /**
     * Assunto da OS sem o prefixo de etapa. O Cadastro grava "Cadastro - ABC1D23"
     * e esse texto nunca é atualizado quando a OS muda de etapa; aqui o prefixo
     * velho sai (nada é reescrito no banco).
     */
    public static function semPrefixoDeEtapa(?string $assunto): string
    {
        $assunto = trim((string) $assunto);
        $etapas = implode('|', array_map(fn ($etapa) => preg_quote($etapa, '/'), [
            self::STAGE_CADASTRO, self::STAGE_DIAGNOSTICO, self::STAGE_ORDEM_SERVICO, self::STAGE_ORCAMENTO,
            self::STAGE_NEGOCIACAO, self::STAGE_EM_EXECUCAO, self::STAGE_VEICULO_TESTE, self::STAGE_FATURAMENTO,
            self::STAGE_POS_VENDA, self::STAGE_CONCLUIDA, self::STAGE_CANCELADA,
        ]));

        return trim((string) preg_replace('/^(?:'.$etapas.')\s+-(?:\s+|$)/iu', '', $assunto));
    }

    /**
     * Quando a OS entrou na etapa (a última entrada registrada no histórico):
     * é daí que sai o "horário de início do orçamento". Sem registro, null.
     */
    public function etapaIniciadaEm(string $etapa): ?Carbon
    {
        $evento = ServiceOrderEvent::query()
            ->where('service_order_id', $this->id)
            ->where('tipo_evento', 'mudanca_etapa')
            ->where('etapa_destino', $etapa)
            ->orderByDesc('ocorrido_em')
            ->orderByDesc('id')
            ->first();

        return $evento?->ocorrido_em;
    }

    /**
     * Título para o formulário de edição: o prefixo "Cadastro - " que o Cadastro
     * gravava nas OS antigas passa a aparecer como "Ordem de Serviço - " (nada é
     * reescrito no banco até alguém salvar a tela).
     */
    public function tituloParaEdicao(): string
    {
        $assunto = (string) $this->subject;

        return preg_replace('/^Cadastro\s+-(\s+|$)/iu', self::STAGE_ORDEM_SERVICO.' - ', $assunto) ?? $assunto;
    }

    /**
     * Como a OS se identifica: o assunto sem a etapa velha ou, se não sobrar nada, a placa.
     */
    public function identificacao(): string
    {
        $texto = self::semPrefixoDeEtapa($this->subject);

        return $texto !== '' ? $texto : (string) ($this->vehicle?->placa ?: 'OS #'.$this->id);
    }

    /**
     * Nome da etapa em que a OS está AGORA (em português).
     */
    public function etapaAtualRotulo(): string
    {
        $etapa = (string) $this->workshop_status;
        $chave = 'service_order::app.stages.'.$etapa;
        $texto = trans($chave);

        return $etapa === '' ? '' : ($texto === $chave ? $etapa : $texto);
    }

    /**
     * Título da tela da OS com a etapa ATUAL: "#11 · Orçamento - ABC1D23".
     */
    public function tituloAtual(): string
    {
        $etapa = $this->etapaAtualRotulo();

        return '#'.$this->id.' · '.($etapa !== '' ? $etapa.' - ' : '').$this->identificacao();
    }

    /**
     * "Anotações e anexos" (o "+" da OS): do mais novo para o mais antigo.
     */
    public function extras()
    {
        return $this->hasMany(ServiceOrderExtra::class, 'quote_id')->orderByDesc('id');
    }

    /**
     * O laudo do diagnóstico tem evidência: um código de falha, uma medição ou uma foto do laudo,
     * ou foto/vídeo enviados na aba de mídia do diagnóstico (reunião de 19/11/2026).
     */
    public function temEvidenciaDoDiagnostico(): bool
    {
        return $this->laudoItems()->exists()
            || $this->attachments()->whereIn('tipo', [ServiceOrderAttachment::TIPO_DIAGNOSTICO_FOTO, ServiceOrderAttachment::TIPO_DIAGNOSTICO_VIDEO])->exists();
    }

    public function laudoItems()
    {
        return $this->hasMany(ServiceOrderLaudoItem::class, 'quote_id');
    }

    public function queixaTipo()
    {
        return $this->belongsTo(DiagnosticoQueixaTipo::class, 'queixa_tipo_id');
    }

    /**
     * Itens de serviço (operações) -- a unidade de apontamento de tempo.
     */
    public function operations()
    {
        return $this->hasMany(ServiceOrderItem::class, 'quote_id')->where('tipo', ServiceOrderItem::TIPO_SERVICO);
    }

    /**
     * Cliente de frota com faturamento mensal: única exceção à regra de
     * "sem NF e sem pagamento não sai".
     */
    public function isFrotaFaturamentoMensal(): bool
    {
        return (bool) ($this->vehicle?->frota);
    }

    /**
     * Técnico responsável pelo diagnóstico (designado) ou, na falta, o
     * mecânico da OS.
     */
    public function tecnicoDesignadoId(): ?int
    {
        return $this->diagnostico_mechanic_id ?: $this->mechanic_id;
    }

    public function boletos()
    {
        return $this->hasMany(ServiceOrderBoleto::class, 'quote_id');
    }

    public function feedback()
    {
        return $this->hasOne(ServiceOrderFeedback::class, 'quote_id');
    }

    /**
     * Every other order for the same vehicle, most recent first -- the
     * "histórico de manutenção" shown at the Pós-Venda stage.
     */
    public function maintenanceHistory()
    {
        return static::withoutGlobalScope('serviceOrder')
            ->where('vehicle_id', $this->vehicle_id)
            ->where('id', '!=', $this->id)
            ->orderByDesc('created_at');
    }

    /**
     * Current timer state, derived from the time_logs trail: null if never
     * started, 'Pausado' if the last event was a pause, 'Concluído' if the
     * last event finished it, otherwise 'Em Andamento'.
     */
    public function getTimerStatusAttribute(): ?string
    {
        $last = $this->timeLogs->last();

        if (! $last) {
            return null;
        }

        return match ($last->action) {
            ServiceOrderTimeLog::ACTION_PAUSADO => 'Pausado',
            ServiceOrderTimeLog::ACTION_CONCLUIDO => 'Concluído',
            default => 'Em Andamento',
        };
    }

    /**
     * Seconds actually worked so far: the sum of every Iniciado/Retomado →
     * Pausado/Concluído interval, plus the still-open interval (if any) up
     * to now.
     */
    public function getElapsedSecondsAttribute(): int
    {
        $seconds = 0;
        $openedAt = null;

        foreach ($this->timeLogs as $log) {
            if (in_array($log->action, [ServiceOrderTimeLog::ACTION_INICIADO, ServiceOrderTimeLog::ACTION_RETOMADO], true)) {
                $openedAt = $log->created_at;

                continue;
            }

            if ($openedAt) {
                $seconds += $log->created_at->getTimestamp() - $openedAt->getTimestamp();
                $openedAt = null;
            }
        }

        if ($openedAt) {
            $seconds += now()->getTimestamp() - $openedAt->getTimestamp();
        }

        return $seconds;
    }

    /**
     * The most recent pause's justificativa, only while still paused (null
     * once resumed/finished) -- shown on the Execução panel.
     */
    public function getPausaJustificativaAttribute(): ?string
    {
        if ($this->timer_status !== 'Pausado') {
            return null;
        }

        return $this->timeLogs->last()?->justificativa;
    }

    /**
     * Particular/Empresa, derived from the linked Person's organization_id -- not stored.
     */
    public function getTipoClienteAttribute(): string
    {
        return $this->person?->organization_id ? 'Empresa' : 'Particular';
    }
}
