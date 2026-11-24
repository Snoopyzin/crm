<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de cada SMS (enviado, falhou ou bloqueado pelo limite mensal).
 */
class SmsLog extends Model
{
    protected $table = 'sms_logs';

    protected $guarded = [];

    protected $casts = [
        'custo' => 'float',
    ];
}
