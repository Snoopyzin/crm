<?php

namespace Webkul\PartInventory\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\PartInventory\Contracts\PartCategory as PartCategoryContract;

class PartCategory extends Model implements PartCategoryContract
{
    protected $table = 'part_categories';

    protected $fillable = [
        'name',
    ];

    public function parts()
    {
        return $this->hasMany(PartProxy::modelClass());
    }
}
