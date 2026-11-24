<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderTimeLog;
use Webkul\ServiceOrder\Repositories\ServiceOrderRepository;

/**
 * Avanço de etapa da OS: confere os campos obrigatórios, aceita avanço
 * forçado (com justificativa, evento e alerta crítico) e grava os eventos
 * de cada passo.
 */
class StageAdvanceService
{
    /** Verdadeiro só durante o avanço feito por este serviço. */
    protected static bool $advancing = false;

    public function __construct(
        protected StageGate $gate,
        protected ServiceOrderRepository $repository,
    ) {}

    public static function isAdvancing(): bool
    {
        return static::$advancing;
    }

    /**
     * @return array{avancou: bool, faltando: string[], forcado: bool}
     */
    public function advance(ServiceOrder $serviceOrder, string $toStage, bool $force = false, ?string $justificativa = null): array
    {
        if (! $serviceOrder->canAdvanceTo($toStage)) {
            throw ValidationException::withMessages(['stage' => ['Essa ordem de serviço não pode avançar para essa etapa agora.']]);
        }

        $faltando = $this->gate->missing($serviceOrder, $toStage);
        $forcado = false;

        if ($faltando) {
            if (! $force) {
                return ['avancou' => false, 'faltando' => $faltando, 'forcado' => false];
            }

            if (trim((string) $justificativa) === '') {
                throw ValidationException::withMessages(['justificativa' => ['Informe a justificativa para forçar o avanço.']]);
            }

            $forcado = true;

            ServiceOrderEvent::record($serviceOrder, 'bloqueio_forcado', [
                'faltava' => $faltando,
                'justificativa' => $justificativa,
                // Avanço forçado de pagamento/saída também vai ao proprietário.
                'escala_dono' => $this->gate->isSaida($serviceOrder, $toStage),
            ], $serviceOrder->workshop_status, $toStage);
        }

        $semNf = $this->gate->isSaida($serviceOrder, $toStage)
            && ! $serviceOrder->isFrotaFaturamentoMensal()
            && ! $this->gate->hasNotaFiscal($serviceOrder);

        $from = $serviceOrder->workshop_status;

        static::$advancing = true;

        try {
            $this->repository->update(['workshop_status' => $toStage], $serviceOrder->id);
        } finally {
            static::$advancing = false;
        }

        $serviceOrder->refresh();

        $this->afterArriving($serviceOrder, $from, $toStage, $semNf);

        return ['avancou' => true, 'faltando' => $faltando, 'forcado' => $forcado];
    }

    protected function afterArriving(ServiceOrder $serviceOrder, string $from, string $to, bool $semNf): void
    {
        if ($to === ServiceOrder::STAGE_EM_EXECUCAO) {
            $serviceOrder->forceFill(['execucao_liberada_em' => now()])->save();

            ServiceOrderEvent::record($serviceOrder, 'execucao_liberada', [], $from, $to);

            // Cronômetro geral da OS (comportamento que já existia).
            ServiceOrderTimeLog::create([
                'quote_id' => $serviceOrder->id,
                'mechanic_id' => $serviceOrder->mechanic_id,
                'action' => ServiceOrderTimeLog::ACTION_INICIADO,
            ]);
        }

        if ($from === ServiceOrder::STAGE_FATURAMENTO && $to === ServiceOrder::STAGE_POS_VENDA) {
            $serviceOrder->forceFill(['saida_em' => now()])->save();

            ServiceOrderEvent::record($serviceOrder, 'saida_veiculo', ['sem_nf' => $semNf], $from, $to);
        }
    }
}
