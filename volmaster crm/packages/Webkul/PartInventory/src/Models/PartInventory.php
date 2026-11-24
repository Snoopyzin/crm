<?php

namespace Webkul\PartInventory\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Webkul\PartInventory\Contracts\PartInventory as PartInventoryContract;
use Webkul\Warehouse\Models\LocationProxy;
use Webkul\Warehouse\Models\WarehouseProxy;

class PartInventory extends Model implements PartInventoryContract
{
    protected $table = 'part_inventories';

    protected $fillable = [
        'in_stock',
        'allocated',
        'part_id',
        'warehouse_id',
        'warehouse_location_id',
    ];

    /**
     * Quantity actually available to allocate (in_stock minus already allocated).
     */
    protected function onHand(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->in_stock - $this->allocated,
        );
    }

    public function part()
    {
        return $this->belongsTo(PartProxy::modelClass());
    }

    public function warehouse()
    {
        return $this->belongsTo(WarehouseProxy::modelClass());
    }

    public function location()
    {
        return $this->belongsTo(LocationProxy::modelClass(), 'warehouse_location_id');
    }

    public function movements()
    {
        return $this->hasMany(PartStockMovementProxy::modelClass());
    }
}
