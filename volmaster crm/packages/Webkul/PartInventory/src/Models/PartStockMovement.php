<?php

namespace Webkul\PartInventory\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\PartInventory\Contracts\PartStockMovement as PartStockMovementContract;
use Webkul\User\Models\UserProxy;

class PartStockMovement extends Model implements PartStockMovementContract
{
    protected $table = 'part_stock_movements';

    protected $fillable = [
        'part_inventory_id',
        'type',
        'quantity',
        'reason',
        'reference_id',
        'user_id',
    ];

    public function partInventory()
    {
        return $this->belongsTo(PartInventoryProxy::modelClass());
    }

    public function user()
    {
        return $this->belongsTo(UserProxy::modelClass());
    }
}
