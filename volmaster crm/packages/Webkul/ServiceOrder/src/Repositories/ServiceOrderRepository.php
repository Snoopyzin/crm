<?php

namespace Webkul\ServiceOrder\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\ServiceOrder\Models\ServiceOrder;

class ServiceOrderRepository extends Repository
{
    /**
     * Specify fields that are searchable.
     *
     * @var array
     */
    protected $fieldSearchable = [
        'subject',
        'description',
    ];

    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return ServiceOrder::class;
    }

    /**
     * Create a new service order. Only header fields -- items are added
     * afterwards via ServiceOrderItemRepository.
     */
    public function create(array $data)
    {
        $data['subject'] = $data['servico'] ?? ($data['subject'] ?? null);
        $data['workshop_status'] = $data['workshop_status'] ?? ServiceOrder::STATUS_ABERTA;
        $data['user_id'] = $data['user_id'] ?? auth()->id();
        $data['sub_total'] = 0;
        $data['grand_total'] = 0;

        return parent::create($data);
    }

    /**
     * Update a service order's header fields.
     */
    public function update(array $data, $id, $attributes = [])
    {
        if (isset($data['servico'])) {
            $data['subject'] = $data['servico'];
        }

        return parent::update($data, $id);
    }

    /**
     * Recompute totals from the order's current line items: sub_total is the
     * gross (before discounts), grand_total subtracts each item's discount
     * and then any legacy order-level discount. Discounts given in
     * Negociação live on the items themselves (spread across them), so
     * sub_total - grand_total is always the order's total discount.
     */
    public function recalculateTotals(ServiceOrder $serviceOrder): ServiceOrder
    {
        $serviceOrder->load('items');

        $subTotal = round($serviceOrder->items->sum(fn ($item) => $item->gross_total), 2);

        $netItems = round($serviceOrder->items->sum(fn ($item) => $item->line_total), 2);

        $afterPercent = $netItems - ($netItems * ($serviceOrder->discount_percent ?? 0) / 100);

        $grandTotal = max(0, $afterPercent - ($serviceOrder->discount_amount ?? 0));

        $serviceOrder->forceFill([
            'sub_total' => $subTotal,
            'grand_total' => round($grandTotal, 2),
        ])->save();

        return $serviceOrder->refresh();
    }
}
