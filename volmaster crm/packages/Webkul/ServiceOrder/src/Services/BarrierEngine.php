<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Models\BarrierInstance;
use Webkul\ServiceOrder\Models\BarrierRule;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;

/**
 * Motor de barreiras de prazo.
 *
 * - O evento de início (ex.: diagnostico_fim) abre a barreira.
 * - O evento de fim (ex.: orcamento_enviado) fecha e avisa "resolvido".
 * - O job a cada minuto (tick) dispara o nível 1 no vencimento e escala aos
 *   níveis 2 e 3; cada nível dispara uma única vez.
 *
 * Prazos contam em horário comercial da oficina, exceto as barreiras
 * críticas e as marcadas como "relógio corrido" (48 h, 7 dias...). Tudo é lido
 * de barrier_rules / workshop_settings.
 */
class BarrierEngine
{
    protected const NAO_ABRE_BARREIRA = ['alerta_enviado', 'alerta_resolvido'];

    public function __construct(
        protected AlertService $alerts,
        protected OperationService $operations,
        protected BarrierConditions $conditions,
    ) {}

    /**
     * Reage a um evento recém-gravado da OS.
     */
    public function handleEvent(ServiceOrderEvent $event): void
    {
        if (! StageGate::active() || in_array($event->tipo_evento, self::NAO_ABRE_BARREIRA, true)) {
            return;
        }

        BarrierRule::ensureDefaultsOnce();

        $rules = BarrierRule::where('ativo', true)->get()->filter(
            fn (BarrierRule $rule) => in_array($event->tipo_evento, $rule->eventosInicio(), true)
                || in_array($event->tipo_evento, $rule->eventosFim(), true)
        );

        if ($rules->isEmpty()) {
            return;
        }

        $serviceOrder = ServiceOrder::find($event->service_order_id);

        if (! $serviceOrder) {
            return;
        }

        foreach ($rules as $rule) {
            if (in_array($event->tipo_evento, $rule->eventosFim(), true)
                && $this->conditions->passes($rule->condicao_fim, $serviceOrder, $event)) {
                $this->close($rule, $event);
            }

            if (in_array($event->tipo_evento, $rule->eventosInicio(), true)
                && $this->conditions->passes($rule->condicao_inicio, $serviceOrder, $event)) {
                $this->open($rule, $event, $serviceOrder);
            }
        }
    }

    /**
     * Verificação periódica (a cada minuto): dispara os níveis vencidos.
     */
    public function tick(?Carbon $now = null): int
    {
        if (! StageGate::active()) {
            return 0;
        }

        $now ??= now();
        $fired = 0;

        BarrierRule::ensureDefaultsOnce();

        $rules = BarrierRule::where('ativo', true)->get()->keyBy('codigo');

        foreach (BarrierInstance::abertas()->get() as $instance) {
            $rule = $rules->get($instance->barrier_code);

            if (! $rule) {
                continue;
            }

            $serviceOrder = ServiceOrder::find($instance->service_order_id);

            // OS apagada ou cancelada: a barreira deixa de valer, sem alerta.
            if (! $serviceOrder || $serviceOrder->workshop_status === ServiceOrder::STAGE_CANCELADA) {
                $instance->update(['status' => BarrierInstance::STATUS_CUMPRIDA, 'fechada_em' => $now, 'cumprida_no_prazo' => false]);

                continue;
            }

            $fired += $rule->modo === BarrierRule::MODO_OPERACAO
                ? $this->tickOperation($rule, $instance, $serviceOrder, $now)
                : $this->tickMinutes($rule, $instance, $serviceOrder, $now);
        }

        return $fired;
    }

    protected function open(BarrierRule $rule, ServiceOrderEvent $event, ServiceOrder $serviceOrder): void
    {
        if ($rule->modo === BarrierRule::MODO_IMEDIATO) {
            $this->openImmediate($rule, $event, $serviceOrder);

            return;
        }

        $reference = $this->reference($rule, $event);

        $alreadyOpen = BarrierInstance::abertas()
            ->where('service_order_id', $serviceOrder->id)
            ->where('barrier_code', $rule->codigo)
            ->where('referencia_id', $reference)
            ->exists();

        if ($alreadyOpen) {
            return;
        }

        $openedAt = Carbon::instance($event->ocorrido_em);
        $data = [
            'service_order_id' => $serviceOrder->id,
            'barrier_code' => $rule->codigo,
            'referencia_id' => $reference,
            'aberta_em' => $openedAt,
        ];

        if ($rule->modo !== BarrierRule::MODO_OPERACAO) {
            $prazo = $this->prazoMinutos($rule, $event);
            $dueAt = $this->dueAt($rule, $event, $openedAt, $prazo);

            // Sem prazo conhecido (ex.: queixa sem tempo alvo), não há barreira.
            if (! $dueAt) {
                return;
            }

            $data['vence_em'] = $dueAt;

            foreach ([2, 3] as $nivel) {
                if ($levelAt = $this->levelAt($rule, $nivel, $dueAt, $prazo)) {
                    $data["nivel{$nivel}_em"] = $levelAt;
                }
            }
        }

        BarrierInstance::create($data);
    }

    /**
     * Prazo em minutos: fixo na regra ou, no modo dinâmico, vindo do evento.
     */
    protected function prazoMinutos(BarrierRule $rule, ServiceOrderEvent $event): ?int
    {
        if ($rule->modo === BarrierRule::MODO_DINAMICO && $rule->prazo_campo) {
            $fromEvent = $event->dados_json[$rule->prazo_campo] ?? null;

            if (is_numeric($fromEvent) && (int) $fromEvent > 0) {
                return (int) $fromEvent;
            }
        }

        return $rule->prazo_minutos ? (int) $rule->prazo_minutos : null;
    }

    protected function dueAt(BarrierRule $rule, ServiceOrderEvent $event, Carbon $openedAt, ?int $prazo): ?Carbon
    {
        $hours = new BusinessHours;
        $calendar = $rule->critico || ! $rule->conta_horario_comercial;

        return match ($rule->modo) {
            BarrierRule::MODO_FIM_DO_DIA => $this->fimDoDia($openedAt, $calendar, $hours),
            BarrierRule::MODO_DATA_ALVO => $this->dueFromTarget($event, $prazo),
            default => $prazo === null ? null : $this->somar($openedAt, $prazo, $calendar, $hours),
        };
    }

    /**
     * Soma $minutos (reais, da regra) a $de, já com o divisor do modo de teste.
     * Com o modo desligado o resultado é idêntico ao de sempre.
     */
    protected function somar(Carbon $de, int|float $minutos, bool $calendar, BusinessHours $hours): Carbon
    {
        $efetivos = ModoTeste::minutos($minutos);

        return $calendar ? $de->copy()->addSeconds((int) round($efetivos * 60)) : $hours->addMinutes($de, $efetivos);
    }

    /**
     * "Fim do dia": o fechamento do expediente (ou do dia corrido). No modo de
     * teste, o tempo que falta até o fechamento é dividido.
     */
    protected function fimDoDia(Carbon $abertaEm, bool $calendar, BusinessHours $hours): Carbon
    {
        $fim = $calendar ? $abertaEm->copy()->endOfDay()->startOfSecond() : $hours->endOfBusinessDay($abertaEm);

        if (! ModoTeste::ativo()) {
            return $fim;
        }

        $restante = $calendar
            ? max(0, $fim->getTimestamp() - $abertaEm->getTimestamp()) / 60
            : $hours->diffMinutes($abertaEm, $fim);

        return $this->somar($abertaEm, $restante, $calendar, $hours);
    }

    /**
     * Data-alvo informada no evento (prazo_em) menos a antecedência da regra.
     */
    protected function dueFromTarget(ServiceOrderEvent $event, ?int $antecedencia): ?Carbon
    {
        $target = $event->dados_json['prazo_em'] ?? null;

        if (! $target) {
            return null;
        }

        try {
            // A data prometida é do cliente e não muda; só a antecedência do alerta é dividida.
            return Carbon::parse($target)->subSeconds((int) round(ModoTeste::minutos((int) $antecedencia) * 60));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Momento em que o nível 2 ou 3 dispara, ou null se a regra não o define.
     */
    protected function levelAt(BarrierRule $rule, int $nivel, Carbon $dueAt, ?int $prazo): ?Carbon
    {
        if (! $rule->destinos($nivel)) {
            return null;
        }

        $hours = new BusinessHours;
        $calendar = $rule->critico || ! $rule->conta_horario_comercial;
        $percentual = (int) $rule->{"nivel{$nivel}_apos_percentual"};
        $offset = $rule->offsetMinutos($nivel);

        // % do prazo real, arredondado em minutos como sempre foi; o divisor entra em somar().
        if ($percentual > 0 && $prazo) {
            return $this->somar($dueAt, (int) round($prazo * $percentual / 100), $calendar, $hours);
        }

        if ($offset === null) {
            return null;
        }

        if ($rule->unidade_offset === 'dias') {
            // Modo de teste: N dias viram N x 24 h ÷ divisor, contados como os demais prazos.
            if (ModoTeste::ativo()) {
                return $this->somar($dueAt, $offset * 1440, $calendar, $hours);
            }

            return $calendar ? $dueAt->copy()->addDays($offset) : $hours->addBusinessDays($dueAt, $offset);
        }

        return $this->somar($dueAt, $offset, $calendar, $hours);
    }

    /**
     * Identifica a instância dentro da OS: a operação (item), a reclamação...
     */
    protected function reference(BarrierRule $rule, ServiceOrderEvent $event): int
    {
        $dados = $event->dados_json ?? [];

        if (isset($dados['referencia_id'])) {
            return (int) $dados['referencia_id'];
        }

        return $rule->modo === BarrierRule::MODO_OPERACAO ? (int) ($dados['item_id'] ?? 0) : 0;
    }

    /**
     * Barreira crítica: alerta na hora, sem prazo.
     */
    protected function openImmediate(BarrierRule $rule, ServiceOrderEvent $event, ServiceOrder $serviceOrder): void
    {
        $dados = $event->dados_json ?? [];

        // A saída só é crítica quando o veículo saiu sem nota fiscal
        // (também vale para bancos cujas regras foram criadas antes da condição).
        if ($rule->codigo === 'critico_saida_sem_nota' && empty($dados['sem_nf'])) {
            return;
        }

        $when = Carbon::instance($event->ocorrido_em);

        $instance = BarrierInstance::create([
            'service_order_id' => $serviceOrder->id,
            'barrier_code' => $rule->codigo,
            'referencia_id' => 0,
            'aberta_em' => $when,
            'vence_em' => $when,
            'nivel_atual' => 1,
            'status' => BarrierInstance::STATUS_CUMPRIDA,
            'cumprida_no_prazo' => false,
            'fechada_em' => $when,
        ]);

        $this->alerts->fire($rule, 1, $serviceOrder, $instance, null, $when);

        // Níveis seguintes (proprietário) só quando o evento pede a escalada.
        if (! empty($dados['escala_dono'])) {
            foreach ([2, 3] as $nivel) {
                if ($rule->destinos($nivel)) {
                    $this->alerts->fire($rule, $nivel, $serviceOrder, $instance, null, $when);
                }
            }
        }
    }

    protected function close(BarrierRule $rule, ServiceOrderEvent $event): void
    {
        $instances = BarrierInstance::abertas()
            ->where('service_order_id', $event->service_order_id)
            ->where('barrier_code', $rule->codigo)
            ->where('referencia_id', $this->reference($rule, $event))
            ->get();

        foreach ($instances as $instance) {
            $closedAt = Carbon::instance($event->ocorrido_em);

            $instance->update([
                'status' => BarrierInstance::STATUS_CUMPRIDA,
                'fechada_em' => $closedAt,
                // Cumprida no prazo = nenhum nível chegou a disparar.
                'cumprida_no_prazo' => $instance->nivel_atual === 0,
            ]);

            $this->alerts->resolveInstance($instance, $closedAt);
        }
    }

    protected function tickMinutes(BarrierRule $rule, BarrierInstance $instance, ServiceOrder $serviceOrder, Carbon $now): int
    {
        $fired = 0;

        foreach ([1, 2, 3] as $nivel) {
            $dueAt = $nivel === 1 ? $instance->vence_em : $instance->{"nivel{$nivel}_em"};

            // Nível que a regra não define (ex.: reclamação não tem nível 2).
            if (! $dueAt) {
                continue;
            }

            if ($now->lt($dueAt)) {
                break;
            }

            if ($instance->nivel_atual >= $nivel) {
                continue;
            }

            if ($this->claimLevel($instance, $nivel)) {
                $this->alerts->fire($rule, $nivel, $serviceOrder, $instance, null, Carbon::instance($instance->vence_em));
                $fired++;
            }
        }

        return $fired;
    }

    protected function tickOperation(BarrierRule $rule, BarrierInstance $instance, ServiceOrder $serviceOrder, Carbon $now): int
    {
        $item = ServiceOrderItem::find($instance->referencia_id);
        $padrao = $item ? $this->operations->padraoSeconds($item) : 0;

        if (! $item || $padrao <= 0) {
            return 0;
        }

        $worked = $this->operations->workedSeconds($item, $now);
        $fired = 0;

        foreach ([1, 2] as $nivel) {
            $percentual = (int) $rule->{"nivel{$nivel}_percentual"};

            if ($percentual <= 0 || $instance->nivel_atual >= $nivel) {
                continue;
            }

            $limit = $padrao * $percentual / 100;

            if ($worked <= $limit) {
                break;
            }

            if ($this->claimLevel($instance, $nivel)) {
                // Hora aproximada em que o limite foi cruzado (pausas não contam).
                $crossedAt = $now->copy()->subSeconds((int) max(0, $worked - $limit));
                $this->alerts->fire($rule, $nivel, $serviceOrder, $instance, $item, $crossedAt);
                $fired++;
            }
        }

        return $fired;
    }

    /**
     * Marca o nível como disparado, uma única vez (trava a linha para o caso
     * de dois jobs rodarem juntos).
     */
    protected function claimLevel(BarrierInstance $instance, int $nivel): bool
    {
        return DB::transaction(function () use ($instance, $nivel) {
            $fresh = BarrierInstance::whereKey($instance->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->status === BarrierInstance::STATUS_CUMPRIDA || $fresh->nivel_atual >= $nivel) {
                return false;
            }

            $fresh->update(['nivel_atual' => $nivel, 'status' => BarrierInstance::STATUS_VENCIDA]);

            $instance->nivel_atual = $nivel;
            $instance->status = BarrierInstance::STATUS_VENCIDA;

            return true;
        });
    }
}
