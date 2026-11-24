<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Services\Alcada\AlcadaCatalog;

/**
 * Uma linha da matriz de alçadas da oficina: limites de "decide sozinho" e do
 * gerente. Em branco até o dono preencher.
 */
class AlcadaRegra extends Model
{
    public $incrementing = false;

    protected $table = 'alcada_regras';

    protected $primaryKey = 'tema';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'limite_sozinho' => 'float',
        'limite_gerente' => 'float',
        'ativo' => 'boolean',
    ];

    /** @var array<string, bool> */
    protected static array $seeded = [];

    public static function ensureDefaults(): void
    {
        $existentes = static::pluck('tema')->flip();

        foreach (array_keys(AlcadaCatalog::temas()) as $tema) {
            if (! $existentes->has($tema)) {
                static::create(['tema' => $tema, 'ativo' => true]);
            }
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
}
