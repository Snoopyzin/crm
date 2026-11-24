<?php

namespace Webkul\PartInventory\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\PartInventory\Contracts\Service as ServiceContract;

class Service extends Model implements ServiceContract
{
    protected $table = 'services';

    protected $fillable = [
        'nome',
        'descricao',
        'part_category_id',
        'preco_padrao',
        'tempo_padrao_horas',
    ];

    public function category()
    {
        return $this->belongsTo(PartCategoryProxy::modelClass(), 'part_category_id');
    }
}
