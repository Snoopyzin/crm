<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Webkul\ServiceOrder\Services\Bonus\BonusCatalog;

/**
 * Trava que zera o bônus do mês. Registrada por quem constatou (com
 * evidência) e confirmada pelo gerente -- ou pelo dono, quando a trava é do
 * gerente. Nunca é apagada.
 */
class BonusTrava extends Model
{
    public const PENDENTE = 'pendente';

    public const CONFIRMADA = 'confirmada';

    public const REJEITADA = 'rejeitada';

    protected $table = 'bonus_travas';

    protected $guarded = [];

    protected $casts = [
        'decidida_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Travas não podem ser apagadas; rejeite com um comentário.');
        });
    }

    public function colaborador()
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function getTipoNomeAttribute(): string
    {
        return BonusCatalog::travas()[$this->cargo][$this->tipo]['nome'] ?? $this->tipo;
    }
}
