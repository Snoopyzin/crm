<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Nota e valor do bônus de um colaborador por cargo no mês. Depois de
 * aprovada, só muda com estorno justificado (BonusService::estornar).
 */
class BonusApuracao extends Model
{
    public const CALCULADO = 'calculado';

    public const REVISADO = 'revisado';

    public const APROVADO = 'aprovado';

    protected $table = 'bonus_apuracoes';

    protected $guarded = [];

    protected $casts = [
        'nota' => 'float',
        'salario_base' => 'float',
        'percentual_cheio' => 'float',
        'valor' => 'float',
        'zerado_por_trava' => 'boolean',
        'detalhe_json' => 'array',
        'calculado_em' => 'datetime',
        'revisado_em' => 'datetime',
        'ciencia_colaborador_em' => 'datetime',
        'aprovado_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Apurações de bônus não podem ser apagadas.');
        });
    }

    public function colaborador()
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function aprovada(): bool
    {
        return $this->status === self::APROVADO;
    }
}
