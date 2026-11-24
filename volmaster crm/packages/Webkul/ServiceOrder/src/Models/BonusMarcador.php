<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Services\Bonus\BonusCatalog;

/**
 * Marcador do bônus (indicador + peso) de um cargo numa fase.
 */
class BonusMarcador extends Model
{
    protected $table = 'bonus_marcadores';

    protected $guarded = [];

    protected $casts = [
        'resultado' => 'boolean',
        'ativo' => 'boolean',
    ];

    /** @var array<string, bool> */
    protected static array $seeded = [];

    /**
     * Semeia os marcadores padrão dos cargos que ainda não têm nenhum na fase.
     */
    public static function ensureDefaults(): void
    {
        foreach (BonusCatalog::marcadores() as $cargo => $fases) {
            foreach ($fases as $fase => $linhas) {
                if (static::where('cargo', $cargo)->where('fase', $fase)->exists()) {
                    continue;
                }

                foreach ($linhas as $linha) {
                    $codigos = $linha[0];

                    static::create([
                        'cargo' => $cargo,
                        'fase' => $fase,
                        'nome' => $linha[2] ?? null,
                        'codigos' => $codigos,
                        'peso' => $linha[1],
                        'resultado' => $fase === 2 && count(array_intersect(explode(',', $codigos), BonusCatalog::RESULTADO)) > 0,
                    ]);
                }
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

    /**
     * @return string[]
     */
    public function codigosLista(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->codigos))));
    }
}
