<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um link do termo de entrada enviado ao cliente e, depois, o aceite dele (data, hora, IP,
 * aparelho e assinatura). O aceite não se apaga.
 */
class ServiceOrderTermo extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'service_order_termos';

    protected $guarded = [];

    protected $casts = [
        'expira_em' => 'datetime',
        'aceito_em' => 'datetime',
        'autoriza_rodagem' => 'boolean',
    ];

    protected $hidden = ['token_hash', 'assinatura'];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \LogicException('O aceite do termo de entrada não pode ser apagado.');
        });
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'quote_id');
    }
}
