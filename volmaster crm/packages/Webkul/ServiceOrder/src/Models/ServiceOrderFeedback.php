<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ServiceOrderFeedback extends Model
{
    const AVALIACAO_BOM = 'Bom';

    const AVALIACAO_RUIM = 'Ruim';

    protected $table = 'service_order_feedbacks';

    protected $fillable = [
        'quote_id',
        'token',
        'enviado_em',
        'respondido_em',
        'avaliacao',
        'comentario',
    ];

    protected $casts = [
        'enviado_em' => 'datetime',
        'respondido_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $feedback) {
            $feedback->token ??= (string) Str::uuid();
        });
    }

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class, 'quote_id')->withoutGlobalScopes();
    }
}
