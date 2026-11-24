<?php

namespace Webkul\ServiceOrder\Services;

use Webkul\PartInventory\Models\Part;
use Webkul\PartInventory\Models\PartInventory;
use Webkul\PartInventory\Services\PartInventoryService;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Repositories\ServiceOrderRepository;

class ServiceOrderItemService
{
    public function __construct(
        protected PartInventoryService $partInventoryService,
        protected ServiceOrderRepository $serviceOrderRepository
    ) {}

    /**
     * Add a line item (Peça or Serviço) to a service order.
     *
     * Adding a "Peça" item soft-reserves stock (increments allocated) -- it is
     * only actually consumed when the order reaches "Concluída".
     */
    public function addItem(ServiceOrder $serviceOrder, array $data): ServiceOrderItem
    {
        $item = new ServiceOrderItem;
        $item->quote_id = $serviceOrder->id;
        $item->product_id = 0;
        $item->tipo = $data['tipo'];
        $item->name = $data['descricao'];
        $item->price = $data['preco_unitario'];
        $item->discount_percent = $data['discount_percent'] ?? 0;
        $item->discount_amount = $data['discount_amount'] ?? 0;

        // Who executes this line (counts for their commission); defaults to
        // the order's mechanic.
        $item->funcionario_id = ($data['funcionario_id'] ?? null) ?: $serviceOrder->mechanic_id;

        // Item code printed on the OS: the part's own code, or SRV-0001 for
        // a service picked from the catalog.
        $item->sku = $data['tipo'] === ServiceOrderItem::TIPO_PECA
            ? Part::whereKey($data['part_id'] ?? 0)->value('codigo')
            : (! empty($data['service_id']) ? ServiceOrderItem::serviceCode($data['service_id']) : null);

        if ($data['tipo'] === ServiceOrderItem::TIPO_PECA) {
            $item->quantity = $data['quantidade'];
            $item->part_id = $data['part_id'];
            $item->situacao = ServiceOrderItem::SITUACAO_DISPONIVEL;
        } else {
            $item->quantity = 1;
            $item->hours = $data['hours'] ?? null;
        }

        // Item que apareceu depois do orçamento original (identificado na execução).
        $item->adicional = ! empty($data['adicional']);

        $item->total = 0;
        $item->save();
        $item->total = $item->line_total;
        $item->save();

        if ($item->adicional) {
            ServiceOrderEvent::record($serviceOrder, 'item_adicional', ['item_id' => $item->id, 'item' => $item->name, 'tipo' => $item->tipo]);
        }

        if ($item->tipo === ServiceOrderItem::TIPO_PECA && $item->part_id) {
            $partInventory = PartInventory::where('part_id', $item->part_id)->first();

            if ($partInventory) {
                $this->partInventoryService->allocate($partInventory, (int) $item->quantity);
            }
        }

        $this->serviceOrderRepository->recalculateTotals($serviceOrder);

        return $item;
    }

    /**
     * Remove a line item, releasing any stock reservation it held.
     */
    public function removeItem(ServiceOrderItem $item): void
    {
        $serviceOrder = $item->serviceOrder;

        if ($item->tipo === ServiceOrderItem::TIPO_PECA && $item->part_id) {
            $partInventory = PartInventory::where('part_id', $item->part_id)->first();

            if ($partInventory) {
                $this->partInventoryService->release($partInventory, (int) $item->quantity);
            }
        }

        $item->delete();

        $this->serviceOrderRepository->recalculateTotals($serviceOrder);
    }
}
