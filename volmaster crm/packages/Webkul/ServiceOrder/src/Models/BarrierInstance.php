<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma barreira aberta numa OS (ou numa operação da OS).
 */
class BarrierInstance extends Model
{
    public const STATUS_NO_PRAZO = 'no_prazo';

    public const STATUS_VENCIDA = 'vencida';

    public const STATUS_CUMPRIDA = 'cumprida';

    protected $table = 'barrier_instances';

    protected $guarded = [];

    protected $casts = [
        'aberta_em' => 'datetime',
        'vence_em' => 'datetime',
        'nivel2_em' => 'datetime',
        'nivel3_em' => 'datetime',
        'fechada_em' => 'datetime',
        'cumprida_no_prazo' => 'boolean',
    ];

    public function rule()
    {
        return BarrierRule::where('codigo', $this->barrier_code)->first();
    }

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    public function scopeAbertas($query)
    {
        return $query->where('status', '!=', self::STATUS_CUMPRIDA);
    }

    /**
     * Semáforo da OS: no prazo, a vencer (últimos 20% do prazo) ou vencida.
     */
    public function semaforo(): string
    {
        if ($this->status === self::STATUS_VENCIDA) {
            return 'vencida';
        }

        if ($this->vence_em && $this->aberta_em) {
            $total = $this->vence_em->getTimestamp() - $this->aberta_em->getTimestamp();
            $restante = $this->vence_em->getTimestamp() - now()->getTimestamp();

            if ($total > 0 && $restante <= $total * 0.2) {
                return 'a_vencer';
            }
        }

        return 'no_prazo';
    }
}
