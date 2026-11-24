<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessageLog extends Model
{
    const STATUS_ENVIADO = 'Enviado';

    const STATUS_FALHOU = 'Falhou';

    protected $table = 'whatsapp_message_logs';

    protected $fillable = [
        'quote_id',
        'to',
        'template',
        'status',
        'response',
    ];
}
