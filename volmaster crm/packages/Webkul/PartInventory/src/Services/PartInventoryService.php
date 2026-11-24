<?php

namespace Webkul\PartInventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\PartInventory\Models\PartInventory;
use Webkul\PartInventory\Models\PartStockMovement;

class PartInventoryService
{
    /**
     * Apply a real stock movement (entrada/saida/ajuste), updating in_stock and
     * writing an auditable ledger row, all inside one transaction.
     */
    public function applyMovement(
        PartInventory $partInventory,
        string $type,
        int $quantity,
        ?string $reason = null,
        ?int $referenceId = null,
        ?int $userId = null
    ): PartStockMovement {
        return DB::transaction(function () use ($partInventory, $type, $quantity, $reason, $referenceId, $userId) {
            $delta = match ($type) {
                'entrada' => abs($quantity),
                'saida' => -abs($quantity),
                'ajuste' => $quantity,
            };

            if ($partInventory->in_stock + $delta < 0) {
                throw ValidationException::withMessages([
                    'quantity' => [trans('partinventory::app.errors.insufficient-stock')],
                ]);
            }

            $partInventory->increment('in_stock', $delta);

            return PartStockMovement::create([
                'part_inventory_id' => $partInventory->id,
                'type' => $type,
                'quantity' => $delta,
                'reason' => $reason,
                'reference_id' => $referenceId,
                'user_id' => $userId,
            ]);
        });
    }

    /**
     * Reserve stock for a pending consumption (e.g. a service order line item) without
     * writing a ledger row yet -- this is a soft reservation, not a real movement.
     */
    public function allocate(PartInventory $partInventory, int $quantity): void
    {
        $partInventory->increment('allocated', $quantity);
    }

    /**
     * Release a previously reserved quantity (e.g. line item removed or order cancelled).
     */
    public function release(PartInventory $partInventory, int $quantity): void
    {
        $partInventory->decrement('allocated', min($quantity, $partInventory->allocated));
    }

    /**
     * Convert a reservation into a real consumption: decrement in_stock and allocated,
     * and write the auditable ledger row. Used when a service order is completed.
     */
    public function consumeAllocation(PartInventory $partInventory, int $quantity, string $reason, int $referenceId, ?int $userId = null): PartStockMovement
    {
        return DB::transaction(function () use ($partInventory, $quantity, $reason, $referenceId, $userId) {
            $partInventory->decrement('allocated', min($quantity, $partInventory->allocated));
            $partInventory->decrement('in_stock', $quantity);

            return PartStockMovement::create([
                'part_inventory_id' => $partInventory->id,
                'type' => 'saida',
                'quantity' => -$quantity,
                'reason' => $reason,
                'reference_id' => $referenceId,
                'user_id' => $userId,
            ]);
        });
    }
}
