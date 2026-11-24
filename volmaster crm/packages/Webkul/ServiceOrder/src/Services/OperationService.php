<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Models\ServiceOrderTimeLog;

/**
 * Apontamento de tempo por operação (item de serviço da OS): iniciar,
 * pausar, retomar e concluir. O tempo padrão é o de horas do item; pausas
 * não contam como tempo trabalhado.
 */
class OperationService
{
    public const NAO_INICIADA = 'nao_iniciada';

    public const EM_ANDAMENTO = 'em_andamento';

    public const PAUSADA = 'pausada';

    public const CONCLUIDA = 'concluida';

    public function logs(ServiceOrderItem $item): Collection
    {
        return ServiceOrderTimeLog::where('item_id', $item->id)->orderBy('created_at')->orderBy('id')->get();
    }

    public function status(ServiceOrderItem $item): string
    {
        $last = $this->logs($item)->last();

        return match ($last?->action) {
            null => self::NAO_INICIADA,
            ServiceOrderTimeLog::ACTION_PAUSADO => self::PAUSADA,
            ServiceOrderTimeLog::ACTION_CONCLUIDO => self::CONCLUIDA,
            default => self::EM_ANDAMENTO,
        };
    }

    /**
     * Segundos realmente trabalhados: soma dos intervalos
     * Iniciado/Retomado -> Pausado/Concluído, mais o intervalo ainda aberto
     * até $now. Pausas ficam de fora.
     */
    public function workedSeconds(ServiceOrderItem $item, ?Carbon $now = null): int
    {
        $now ??= now();
        $seconds = 0;
        $openedAt = null;

        foreach ($this->logs($item) as $log) {
            if (in_array($log->action, [ServiceOrderTimeLog::ACTION_INICIADO, ServiceOrderTimeLog::ACTION_RETOMADO], true)) {
                $openedAt ??= $log->created_at;

                continue;
            }

            if ($openedAt) {
                $seconds += max(0, $log->created_at->getTimestamp() - $openedAt->getTimestamp());
                $openedAt = null;
            }
        }

        if ($openedAt) {
            $seconds += max(0, $now->getTimestamp() - $openedAt->getTimestamp());
        }

        return $seconds;
    }

    /**
     * Tempo padrão em segundos (horas do item); 0 quando não informado.
     */
    public function padraoSeconds(ServiceOrderItem $item): int
    {
        $segundos = (int) round(((float) $item->hours) * 3600);

        // Modo de teste (sub-opção): 1 h de tempo padrão vira 6 min no cronômetro do técnico e na barreira de operação.
        return ModoTeste::tempoPadraoAtivo() ? (int) round($segundos / ModoTeste::divisor()) : $segundos;
    }

    /**
     * Tempo trabalhado como % do padrão, ou null sem tempo padrão.
     */
    public function percentual(ServiceOrderItem $item, ?Carbon $now = null): ?float
    {
        $padrao = $this->padraoSeconds($item);

        return $padrao > 0 ? round($this->workedSeconds($item, $now) / $padrao * 100, 1) : null;
    }

    /**
     * Cor do cronômetro: verde até 100% do padrão, amarelo até a margem
     * (120%), vermelho acima.
     */
    public function cor(ServiceOrderItem $item, ?Carbon $now = null): string
    {
        $percentual = $this->percentual($item, $now);

        if ($percentual === null || $percentual <= 100) {
            return 'verde';
        }

        return $percentual <= (float) WorkshopSettings::get('tecnico_margem_percentual', 120) ? 'amarelo' : 'vermelho';
    }

    public function start(ServiceOrderItem $item): void
    {
        $serviceOrder = $this->orderOf($item);
        $mechanicId = $this->mechanicIdOf($item, $serviceOrder);

        if ($this->status($item) !== self::NAO_INICIADA) {
            throw ValidationException::withMessages(['operacao' => ['Esta operação já foi iniciada.']]);
        }

        $this->assertNoOtherRunning($mechanicId, $item);

        $this->log($item, $mechanicId, ServiceOrderTimeLog::ACTION_INICIADO);

        ServiceOrderEvent::record($serviceOrder, 'operacao_inicio', [
            'item_id' => $item->id,
            'operacao' => $item->name,
            'padrao_min' => (int) round($this->padraoSeconds($item) / 60),
            'mechanic_id' => $mechanicId,
        ]);
    }

    public function pause(ServiceOrderItem $item, string $motivo): void
    {
        if ($this->status($item) !== self::EM_ANDAMENTO) {
            throw ValidationException::withMessages(['operacao' => ['Só é possível pausar uma operação em andamento.']]);
        }

        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => ['Informe o motivo da pausa.']]);
        }

        $serviceOrder = $this->orderOf($item);
        $mechanicId = $this->mechanicIdOf($item, $serviceOrder);

        $this->log($item, $mechanicId, ServiceOrderTimeLog::ACTION_PAUSADO, $motivo);

        ServiceOrderEvent::record($serviceOrder, 'operacao_pausa', ['item_id' => $item->id, 'motivo' => $motivo]);
    }

    public function resume(ServiceOrderItem $item): void
    {
        if ($this->status($item) !== self::PAUSADA) {
            throw ValidationException::withMessages(['operacao' => ['Só é possível retomar uma operação pausada.']]);
        }

        $serviceOrder = $this->orderOf($item);
        $mechanicId = $this->mechanicIdOf($item, $serviceOrder);

        $this->assertNoOtherRunning($mechanicId, $item);

        $this->log($item, $mechanicId, ServiceOrderTimeLog::ACTION_RETOMADO);

        ServiceOrderEvent::record($serviceOrder, 'operacao_retomada', ['item_id' => $item->id]);
    }

    /**
     * Conclui a operação. Acima da margem (120% do padrão) o motivo do
     * atraso é obrigatório (lista fixa + observação) e o chefe confirma
     * depois (evento operacao_atraso_confirmado).
     */
    public function finish(ServiceOrderItem $item, ?string $motivoAtraso = null, ?string $observacao = null): void
    {
        $status = $this->status($item);

        if (! in_array($status, [self::EM_ANDAMENTO, self::PAUSADA], true)) {
            throw ValidationException::withMessages(['operacao' => ['Só é possível concluir uma operação iniciada.']]);
        }

        $serviceOrder = $this->orderOf($item);
        $mechanicId = $this->mechanicIdOf($item, $serviceOrder);

        $percentual = $this->percentual($item);
        $margem = (float) WorkshopSettings::get('tecnico_margem_percentual', 120);
        $acimaDaMargem = $percentual !== null && $percentual > $margem;

        if ($acimaDaMargem) {
            $motivos = (array) WorkshopSettings::get('motivos_atraso_operacao', []);

            if (! $motivoAtraso || ! in_array($motivoAtraso, $motivos, true)) {
                throw ValidationException::withMessages([
                    'motivo_atraso' => ['A operação passou de '.$margem.'% do tempo padrão: informe o motivo do atraso.'],
                ]);
            }
        }

        $trabalhado = $this->workedSeconds($item);

        $this->log($item, $mechanicId, ServiceOrderTimeLog::ACTION_CONCLUIDO);

        ServiceOrderEvent::record($serviceOrder, 'operacao_fim', [
            'item_id' => $item->id,
            'mechanic_id' => $mechanicId,
            'operacao' => $item->name,
            'trabalhado_min' => round($trabalhado / 60, 1),
            'padrao_min' => (int) round($this->padraoSeconds($item) / 60),
            'percentual' => $percentual,
            'acima_da_margem' => $acimaDaMargem,
            'motivo_atraso' => $acimaDaMargem ? $motivoAtraso : null,
            'observacao' => $acimaDaMargem ? $observacao : null,
        ]);
    }

    /**
     * Todas as operações da OS têm início e fim apontados?
     */
    public function allOperationsComplete(ServiceOrder $serviceOrder): bool
    {
        return $this->pendingOperations($serviceOrder)->isEmpty();
    }

    /**
     * Operações de serviço ainda sem início ou sem fim.
     */
    public function pendingOperations(ServiceOrder $serviceOrder): Collection
    {
        return $serviceOrder->operations()->get()
            ->filter(fn (ServiceOrderItem $item) => $this->status($item) !== self::CONCLUIDA)
            ->values();
    }

    protected function assertNoOtherRunning(?int $mechanicId, ServiceOrderItem $except): void
    {
        if (! $mechanicId) {
            return;
        }

        $others = ServiceOrderTimeLog::whereNotNull('item_id')
            ->where('mechanic_id', $mechanicId)
            ->where('item_id', '!=', $except->id)
            ->orderBy('id')
            ->get()
            ->groupBy('item_id');

        foreach ($others as $logs) {
            if (in_array($logs->last()->action, [ServiceOrderTimeLog::ACTION_INICIADO, ServiceOrderTimeLog::ACTION_RETOMADO], true)) {
                throw ValidationException::withMessages([
                    'operacao' => ['Você já tem uma operação em andamento. Pause ou conclua antes de iniciar outra.'],
                ]);
            }
        }
    }

    protected function log(ServiceOrderItem $item, ?int $mechanicId, string $action, ?string $justificativa = null): void
    {
        ServiceOrderTimeLog::create([
            'quote_id' => $item->quote_id,
            'item_id' => $item->id,
            'mechanic_id' => $mechanicId,
            'action' => $action,
            'justificativa' => $justificativa,
        ]);
    }

    protected function orderOf(ServiceOrderItem $item): ServiceOrder
    {
        return ServiceOrder::findOrFail($item->quote_id);
    }

    protected function mechanicIdOf(ServiceOrderItem $item, ServiceOrder $serviceOrder): ?int
    {
        return $item->funcionario_id ?: $serviceOrder->mechanic_id;
    }
}
