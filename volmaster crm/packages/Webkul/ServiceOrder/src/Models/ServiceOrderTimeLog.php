<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Mechanic\Models\MechanicProxy;

class ServiceOrderTimeLog extends Model
{
    const ACTION_INICIADO = 'Iniciado';

    const ACTION_PAUSADO = 'Pausado';

    const ACTION_RETOMADO = 'Retomado';

    const ACTION_CONCLUIDO = 'Concluido';

    public $timestamps = false;

    protected $table = 'service_order_time_logs';

    protected $fillable = [
        'quote_id',
        'mechanic_id',
        'item_id',
        'action',
        'justificativa',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->created_at ??= now();
        });
    }

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class, 'quote_id')->withoutGlobalScopes();
    }

    public function mechanic()
    {
        return $this->belongsTo(MechanicProxy::modelClass(), 'mechanic_id');
    }
}
