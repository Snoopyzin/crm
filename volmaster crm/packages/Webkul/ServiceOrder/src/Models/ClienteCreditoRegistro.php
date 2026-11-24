<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Webkul\User\Models\User;

/**
 * Uma análise de crédito do cliente (cor, quem registrou, quando, observação). Só inserção:
 * corrigir é registrar de novo, com justificativa.
 */
class ClienteCreditoRegistro extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cliente_credito_registros';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('O histórico de crédito não pode ser alterado; registre uma nova análise.');
        });

        static::deleting(function () {
            throw new LogicException('O histórico de crédito não pode ser apagado; registre uma nova análise.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
