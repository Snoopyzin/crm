<?php

namespace Webkul\ServiceOrder\Observers;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Webkul\PartInventory\Models\PartInventory;
use Webkul\PartInventory\Services\PartInventoryService;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Services\BusinessHours;
use Webkul\ServiceOrder\Services\PrevisaoOs;
use Webkul\ServiceOrder\Services\StageAdvanceService;
use Webkul\ServiceOrder\Services\StageGate;

class ServiceOrderObserver
{
    public function __construct(protected PartInventoryService $partInventoryService) {}

    /**
     * Toda OS nova grava a etapa em que nasceu.
     */
    public function created(ServiceOrder $serviceOrder): void
    {
        if ($serviceOrder->workshop_status) {
            ServiceOrderEvent::record($serviceOrder, 'mudanca_etapa', [], null, $serviceOrder->workshop_status);
        }

        // OS de retorno ou garantia: abre a barreira de diagnóstico em 2 h.
        if (in_array($serviceOrder->tipo_os, ['retorno', 'garantia'], true)) {
            ServiceOrderEvent::record($serviceOrder, 'retorno_aberto', [
                'tipo' => $serviceOrder->tipo_os,
                'os_origem_id' => $serviceOrder->os_origem_id,
            ]);
        }

        if ($serviceOrder->data_previsao) {
            $this->recordPrazo($serviceOrder);
        }
    }

    /**
     * Toda mudança de etapa -- por qualquer caminho do sistema -- grava data,
     * hora e usuário no log de eventos.
     */
    public function updated(ServiceOrder $serviceOrder): void
    {
        if ($serviceOrder->wasChanged('workshop_status')) {
            ServiceOrderEvent::record(
                $serviceOrder,
                'mudanca_etapa',
                [],
                $serviceOrder->getOriginal('workshop_status'),
                $serviceOrder->workshop_status
            );

            // Quando a ficha passa do Atendimento para a OS, ela vai ao chefe
            // (abre a barreira "técnico designado em 30 min").
            if ($serviceOrder->getOriginal('workshop_status') === ServiceOrder::STAGE_CADASTRO
                && $serviceOrder->workshop_status === ServiceOrder::STAGE_ORDEM_SERVICO) {
                ServiceOrderEvent::record($serviceOrder, 'ficha_enviada_chefe');
            }
        }

        // O prazo prometido ao cliente muda: grava o novo prazo (reinicia a barreira).
        if (($serviceOrder->wasChanged('data_previsao') || $serviceOrder->wasChanged('hora_previsao')) && $serviceOrder->data_previsao) {
            $this->recordPrazo($serviceOrder);
        }
    }

    /**
     * Prazo informado ao cliente: com horário (veículo que entra e sai no mesmo
     * dia), vence naquela hora; só com a data, vale até o fim do expediente.
     */
    protected function recordPrazo(ServiceOrder $serviceOrder): void
    {
        $hora = PrevisaoOs::hora($serviceOrder->getAttribute('hora_previsao'));
        $dia = Carbon::parse($serviceOrder->data_previsao)->startOfDay();
        $prazo = $hora !== null
            ? PrevisaoOs::momento($dia, $hora)
            : (new BusinessHours)->endOfBusinessDay($dia->copy());

        $serviceOrder->forceFill(['prazo_prometido_em' => $prazo])->saveQuietly();

        ServiceOrderEvent::record($serviceOrder, 'prazo_informado', ['prazo_em' => $prazo->toDateTimeString()]);
    }

    /**
     * Handle status transitions: lock a completed order, stamp its completion
     * date, consume reserved stock on completion, and release it on cancellation.
     */
    public function updating(ServiceOrder $serviceOrder): void
    {
        if (! $serviceOrder->isDirty('workshop_status')) {
            return;
        }

        $original = $serviceOrder->getOriginal('workshop_status');
        $new = $serviceOrder->workshop_status;

        // Com o piloto ligado, a etapa só muda pelo StageAdvanceService (que
        // confere os campos obrigatórios). Isso fecha qualquer atalho de
        // equipe -- rota antiga, formulário forjado -- que pularia o bloqueio.
        if (StageGate::active() && auth()->guard('user')->check() && ! StageAdvanceService::isAdvancing()) {
            throw ValidationException::withMessages([
                'workshop_status' => ['A etapa só pode ser alterada pelo botão Avançar, que confere os campos obrigatórios.'],
            ]);
        }

        if ($original === ServiceOrder::STATUS_CONCLUIDA && $new !== ServiceOrder::STATUS_CONCLUIDA) {
            throw ValidationException::withMessages([
                'workshop_status' => [trans('service_order::app.errors.completed-status-locked')],
            ]);
        }

        // First time it's billed (or jumps straight past Faturamento): the
        // date commissions are counted by.
        if (! $serviceOrder->faturado_em && in_array($new, [ServiceOrder::STAGE_FATURAMENTO, ServiceOrder::STAGE_POS_VENDA, ServiceOrder::STAGE_CONCLUIDA])) {
            $serviceOrder->faturado_em = now();
        }

        if ($new === ServiceOrder::STATUS_CONCLUIDA && $original !== ServiceOrder::STATUS_CONCLUIDA) {
            $serviceOrder->data_conclusao = now();

            foreach ($this->partItems($serviceOrder) as $item) {
                $partInventory = PartInventory::where('part_id', $item->part_id)->first();

                if ($partInventory) {
                    $this->partInventoryService->consumeAllocation(
                        $partInventory,
                        (int) $item->quantity,
                        "Ordem de Serviço #{$serviceOrder->id}",
                        $serviceOrder->id
                    );
                }
            }
        }

        if ($new === ServiceOrder::STATUS_CANCELADA && $original !== ServiceOrder::STATUS_CANCELADA) {
            foreach ($this->partItems($serviceOrder) as $item) {
                $partInventory = PartInventory::where('part_id', $item->part_id)->first();

                if ($partInventory) {
                    $this->partInventoryService->release($partInventory, (int) $item->quantity);
                }
            }
        }
    }

    /**
     * The order's "Peça" line items that reference a real part.
     */
    protected function partItems(ServiceOrder $serviceOrder)
    {
        return ServiceOrderItem::where('quote_id', $serviceOrder->id)
            ->where('tipo', ServiceOrderItem::TIPO_PECA)
            ->whereNotNull('part_id')
            ->get();
    }
}
