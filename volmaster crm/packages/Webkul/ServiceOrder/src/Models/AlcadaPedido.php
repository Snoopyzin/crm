<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Webkul\ServiceOrder\Services\Alcada\AlcadaCatalog;
use Webkul\User\Models\User;

/**
 * Pedido na fila de alçadas. Depois de decidido (aprovado/recusado), a
 * decisão não muda nem some: só se registra um novo pedido.
 */
class AlcadaPedido extends Model
{
    public const PENDENTE = 'pendente';

    public const ENCAMINHADO = 'encaminhado';

    public const APROVADO = 'aprovado';

    public const RECUSADO = 'recusado';

    public const CONSUMIDO = 'consumido';

    protected $table = 'alcada_pedidos';

    protected $guarded = [];

    protected $casts = [
        'valor' => 'float',
        'sem_gerente' => 'boolean',
        'encaminhado_em' => 'datetime',
        'decidido_em' => 'datetime',
        'consumido_em' => 'datetime',
        'prazo_dono_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Pedidos de alçada não podem ser apagados.');
        });

        static::updating(function (self $pedido) {
            // Uma decisão já tomada só muda para "consumido" (a aprovação foi aplicada).
            if (in_array($pedido->getOriginal('status'), [self::APROVADO, self::RECUSADO, self::CONSUMIDO], true)
                && ! ($pedido->getOriginal('status') === self::APROVADO && $pedido->status === self::CONSUMIDO)) {
                throw new LogicException('Um pedido já decidido não pode ser alterado.');
            }
        });
    }

    public function scopeAbertos($query)
    {
        return $query->whereIn('status', [self::PENDENTE, self::ENCAMINHADO]);
    }

    public function solicitante()
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    public function decisor()
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }

    public function getTemaNomeAttribute(): string
    {
        return AlcadaCatalog::tema($this->tema)['nome'] ?? $this->tema;
    }
}
