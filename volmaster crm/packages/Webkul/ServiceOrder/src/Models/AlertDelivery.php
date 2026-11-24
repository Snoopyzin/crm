<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Situação de um alerta num canal: enviado, entregue, aberto ou falhou.
 */
class AlertDelivery extends Model
{
    public const CANAL_IN_APP = 'in_app';

    public const CANAL_PUSH = 'push';

    public const CANAL_SMS = 'sms';

    protected $table = 'alert_deliveries';

    protected $guarded = [];

    public function alert()
    {
        return $this->belongsTo(WorkshopAlert::class, 'alert_id');
    }
}
