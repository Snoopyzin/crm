<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Webkul\User\Models\User;

/**
 * Alerta para um destinatário. Um por usuário e por nível disparado.
 */
class WorkshopAlert extends Model
{
    public const STATUS_ABERTO = 'aberto';

    public const STATUS_RESOLVIDO = 'resolvido';

    protected $table = 'workshop_alerts';

    protected $guarded = [];

    protected $casts = [
        'critico' => 'boolean',
        'disparado_em' => 'datetime',
        'vencido_em' => 'datetime',
        'lido_em' => 'datetime',
        'resolvido_em' => 'datetime',
        'push_enviado_em' => 'datetime',
        'push_aberto_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $alert) {
            $alert->token ??= Str::random(40);
            $alert->disparado_em ??= now();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function deliveries()
    {
        return $this->hasMany(AlertDelivery::class, 'alert_id');
    }

    public function scopeAbertos($query)
    {
        return $query->where('status', self::STATUS_ABERTO);
    }
}
