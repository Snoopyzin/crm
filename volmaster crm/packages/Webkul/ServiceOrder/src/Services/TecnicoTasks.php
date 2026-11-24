<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;

/**
 * Dados da tela "Minhas tarefas" do técnico e seus indicadores em %.
 *
 * Tudo aqui é montado campo a campo (lista branca): nenhum valor em reais,
 * desconto, crédito ou comissão sai desta classe, nem na tela nem na API.
 */
class TecnicoTasks
{
    public function __construct(protected OperationService $operations, protected StageGate $gate) {}

    /**
     * @return array{diagnosticos: array, operacoes: array, indicadores: array, config: array, agora: int}
     */
    public function forMechanic(int $mechanicId): array
    {
        $now = now();

        return [
            'agora' => $now->getTimestamp(),
            'config' => [
                'margem' => (float) WorkshopSettings::get('tecnico_margem_percentual', 120),
                'causas_atraso_diagnostico' => array_values((array) WorkshopSettings::get('causas_atraso_diagnostico', [])),
                'motivos_pausa' => array_values((array) WorkshopSettings::get('motivos_pausa', [])),
                'motivos_atraso_operacao' => array_values((array) WorkshopSettings::get('motivos_atraso_operacao', [])),
            ],
            'diagnosticos' => $this->diagnosticos($mechanicId),
            'operacoes' => $this->operacoes($mechanicId, $now),
            'indicadores' => $this->indicadores($mechanicId, $now),
        ];
    }

    /**
     * OS em Diagnóstico designadas ao técnico.
     */
    public function diagnosticos(int $mechanicId): array
    {
        return ServiceOrder::with(['vehicle', 'queixaTipo', 'laudoItems.attachment', 'items.part'])
            ->where('workshop_status', ServiceOrder::STAGE_DIAGNOSTICO)
            ->where(function ($query) use ($mechanicId) {
                $query->where('diagnostico_mechanic_id', $mechanicId)
                    ->orWhere(fn ($q) => $q->whereNull('diagnostico_mechanic_id')->where('mechanic_id', $mechanicId));
            })
            ->orderBy('id')
            ->get()
            ->map(fn (ServiceOrder $os) => [
                'id' => $os->id,
                'placa' => $os->vehicle?->placa,
                'veiculo' => trim(($os->vehicle?->marca ?? '').' '.($os->vehicle?->modelo ?? '')),
                'servico' => $os->identificacao(),
                'queixa' => $os->queixaTipo?->nome,
                'queixa_tipo_id' => $os->queixa_tipo_id,
                'tempo_alvo_min' => $os->queixaTipo?->tempoAlvoEfetivoMin(),
                'iniciado_em' => $os->diagnostico_inicio_em?->getTimestamp(),
                'finalizado' => (bool) $os->diagnostico_concluido_em,
                'visto' => $os->diagnostico_visto_status,
                'laudo' => $os->diagnostico,
                'relacao_pecas_em' => $os->relacao_pecas_em?->format('d/m H:i'),
                'passou_do_alvo' => $os->diagnostico_inicio_em && $os->queixaTipo?->tempo_alvo_min
                    ? ((($os->diagnostico_concluido_em ?? now())->getTimestamp() - $os->diagnostico_inicio_em->getTimestamp()) / 60) > $os->queixaTipo->tempoAlvoEfetivoMin()
                    : false,
                'evidencias' => $os->laudoItems->map(fn ($item) => [
                    'tipo' => $item->tipo,
                    'descricao' => $item->descricao,
                    'valor' => $item->valor,
                    'foto' => $item->attachment?->url(),
                ])->all(),
                // Só nome, código e quantidade: sem preço.
                'pecas' => $os->items->where('tipo', ServiceOrderItem::TIPO_PECA)->map(fn ($item) => [
                    'nome' => $item->name,
                    'codigo' => $item->codigo,
                    'quantidade' => (int) $item->quantity,
                ])->values()->all(),
            ])
            ->all();
    }

    /**
     * Operações do técnico nas OS em execução (depois da aprovação do
     * cliente e da liberação do chefe).
     */
    public function operacoes(int $mechanicId, Carbon $now): array
    {
        return ServiceOrderItem::query()
            ->where('tipo', ServiceOrderItem::TIPO_SERVICO)
            ->where(function ($query) use ($mechanicId) {
                $query->where('funcionario_id', $mechanicId)
                    ->orWhere(fn ($q) => $q->whereNull('funcionario_id')->whereHas('serviceOrder', fn ($o) => $o->where('mechanic_id', $mechanicId)));
            })
            ->whereHas('serviceOrder', fn ($query) => $query->where('workshop_status', ServiceOrder::STAGE_EM_EXECUCAO))
            ->with('serviceOrder.vehicle')
            ->orderBy('quote_id')
            ->orderBy('id')
            ->get()
            ->map(function (ServiceOrderItem $item) use ($now) {
                $situacao = $this->operations->status($item);

                return [
                    'id' => $item->id,
                    'os_id' => $item->quote_id,
                    'placa' => $item->serviceOrder?->vehicle?->placa,
                    'servico' => $item->name,
                    'padrao_seg' => $this->operations->padraoSeconds($item),
                    'trabalhado_seg' => $this->operations->workedSeconds($item, $now),
                    'percentual' => $this->operations->percentual($item, $now),
                    'cor' => $this->operations->cor($item, $now),
                    'situacao' => $situacao,
                    'rodando' => $situacao === OperationService::EM_ANDAMENTO,
                ];
            })
            // Concluídas ficam no fim da lista.
            ->sortBy(fn ($op) => $op['situacao'] === OperationService::CONCLUIDA ? 1 : 0)
            ->values()
            ->all();
    }

    /**
     * Indicadores do mês, só em percentual:
     * - eficiência = Σ horas padrão ÷ Σ horas reais (meta configurável, 80%);
     * - operações dentro da margem (até 120% do padrão) ÷ concluídas;
     * - diagnósticos no tempo alvo ÷ diagnósticos com tempo alvo.
     */
    public function indicadores(int $mechanicId, ?Carbon $month = null): array
    {
        $start = ($month ?? now())->copy()->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $margem = (float) WorkshopSettings::get('tecnico_margem_percentual', 120);

        $fins = ServiceOrderEvent::where('tipo_evento', 'operacao_fim')
            ->whereBetween('ocorrido_em', [$start, $end])
            ->get()
            ->filter(fn ($event) => (int) ($event->dados_json['mechanic_id'] ?? 0) === $mechanicId);

        $padrao = $fins->sum(fn ($event) => (float) ($event->dados_json['padrao_min'] ?? 0));
        $real = $fins->sum(fn ($event) => (float) ($event->dados_json['trabalhado_min'] ?? 0));

        $comPadrao = $fins->filter(fn ($event) => (float) ($event->dados_json['padrao_min'] ?? 0) > 0);
        $dentro = $comPadrao->filter(fn ($event) => (float) ($event->dados_json['percentual'] ?? 0) <= $margem)->count();

        $diagnosticos = ServiceOrderEvent::where('tipo_evento', 'diagnostico_fim')
            ->whereBetween('ocorrido_em', [$start, $end])
            ->get()
            ->filter(fn ($event) => (int) ($event->dados_json['mechanic_id'] ?? 0) === $mechanicId && ! empty($event->dados_json['tempo_alvo_min']));

        $diagNoTempo = $diagnosticos->filter(fn ($event) => empty($event->dados_json['passou_do_alvo']))->count();

        $meta = (float) WorkshopSettings::get('tecnico_meta_eficiencia', 80);
        $eficiencia = $real > 0 ? round($padrao / $real * 100, 1) : null;

        return [
            'mes' => $start->translatedFormat('F/Y'),
            'eficiencia' => ['valor' => $eficiencia, 'meta' => $meta, 'cor' => $this->corMeta($eficiencia, $meta)],
            'operacoes_na_margem' => [
                'valor' => $comPadrao->isEmpty() ? null : round($dentro / $comPadrao->count() * 100, 1),
                'quantidade' => $comPadrao->count(),
            ],
            'diagnosticos_no_tempo' => [
                'valor' => $diagnosticos->isEmpty() ? null : round($diagNoTempo / $diagnosticos->count() * 100, 1),
                'quantidade' => $diagnosticos->count(),
            ],
        ];
    }

    /**
     * Verde no alvo, amarelo a partir de 80% da meta, vermelho abaixo.
     */
    protected function corMeta(?float $valor, float $meta): ?string
    {
        if ($valor === null) {
            return null;
        }

        return $valor >= $meta ? 'verde' : ($valor >= $meta * 0.8 ? 'amarelo' : 'vermelho');
    }
}
