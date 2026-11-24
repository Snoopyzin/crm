<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceOrderChecklistItem extends Model
{
    protected $table = 'service_order_checklist_items';

    protected $fillable = [
        'quote_id',
        'descricao',
        'concluido',
    ];

    protected $casts = [
        'concluido' => 'boolean',
    ];

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class, 'quote_id')->withoutGlobalScopes();
    }
}
