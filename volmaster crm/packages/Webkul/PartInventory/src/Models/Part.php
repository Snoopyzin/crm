<?php

namespace Webkul\PartInventory\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\PartInventory\Contracts\Part as PartContract;

class Part extends Model implements PartContract
{
    protected $table = 'parts';

    protected $fillable = [
        'codigo',
        'nome',
        'ncm',
        'cest',
        'part_category_id',
        'valor_unitario',
        'fornecedor',
        'quantidade_minima',
    ];

    public function category()
    {
        return $this->belongsTo(PartCategoryProxy::modelClass(), 'part_category_id');
    }

    public function inventories()
    {
        return $this->hasMany(PartInventoryProxy::modelClass());
    }

    /**
     * Total quantity currently in stock across all warehouses.
     */
    public function getTotalInStockAttribute(): int
    {
        return $this->inventories()->sum('in_stock');
    }

    /**
     * Total allocated (reserved) quantity across all warehouses.
     */
    public function getTotalAllocatedAttribute(): int
    {
        return $this->inventories()->sum('allocated');
    }
}
