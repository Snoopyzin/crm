<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Services\Indicators\IndicatorCatalog;

/**
 * Uma linha da tabela de metas (capítulo 3.3): código, nome, cargo, fórmula,
 * tipo de meta, valor padrão da mentoria, valor da oficina e quem valida.
 */
class IndicatorDefinition extends Model
{
    public const TIPO_MIN = 'min';

    public const TIPO_TETO = 'teto';

    public const TIPO_ZERO = 'zero';

    public const TIPO_NOTA = 'nota';

    public $incrementing = false;

    protected $table = 'indicator_definitions';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'meta_empresa' => 'boolean',
        'ativo' => 'boolean',
        'meta_padrao' => 'float',
        'meta_oficina' => 'float',
    ];

    /** @var array<string, bool> */
    protected static array $seeded = [];

    /**
     * Semeia os indicadores da mentoria que ainda não existem (idempotente).
     * A meta da oficina e o "ativo" que o dono mexeu não são sobrescritos.
     */
    public static function ensureDefaults(): void
    {
        $existing = static::pluck('codigo')->flip();

        foreach (IndicatorCatalog::all() as $ordem => $row) {
            if ($existing->has($row['codigo'])) {
                continue;
            }

            static::create([
                'codigo' => $row['codigo'],
                'nome' => $row['nome'],
                'cargo' => $row['cargo'],
                'formula' => $row['formula'],
                'tipo_meta' => $row['tipo'],
                'meta_padrao' => $row['meta'],
                'meta_empresa' => $row['empresa'],
                'unidade' => $row['unidade'],
                'valida' => $row['valida'],
                'ordem' => $ordem,
            ]);
        }

        static::$seeded[DB::connection()->getDatabaseName()] = true;
    }

    public static function ensureDefaultsOnce(): void
    {
        if (! (static::$seeded[DB::connection()->getDatabaseName()] ?? false)) {
            static::ensureDefaults();
        }
    }

    public static function forgetSeeded(): void
    {
        static::$seeded = [];
    }

    /**
     * Meta em vigor: a da oficina, se preenchida, senão a padrão da mentoria.
     * Null = indicador de "meta da empresa" ainda em branco (sem cor).
     */
    public function meta(): ?float
    {
        return $this->meta_oficina ?? $this->meta_padrao;
    }
}
