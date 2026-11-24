<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\ServiceOrder\Services\ModoTeste;

/**
 * Tipo de queixa com o tempo alvo do diagnóstico (minutos).
 */
class DiagnosticoQueixaTipo extends Model
{
    /** Tipos criados na primeira necessidade; a oficina edita os tempos. */
    public const DEFAULTS = [
        ['nome' => 'Falha elétrica / injeção', 'tempo_alvo_min' => 90],
        ['nome' => 'Freios', 'tempo_alvo_min' => 60],
        ['nome' => 'Suspensão e direção', 'tempo_alvo_min' => 60],
        ['nome' => 'Motor (perda de potência, fumaça)', 'tempo_alvo_min' => 120],
        ['nome' => 'Transmissão / embreagem', 'tempo_alvo_min' => 120],
        ['nome' => 'Revisão / preventiva', 'tempo_alvo_min' => 45],
    ];

    protected $table = 'diagnostico_queixa_tipos';

    protected $guarded = [];

    protected $casts = ['ativo' => 'boolean'];

    public static function ensureDefaults(): void
    {
        if (static::query()->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $row) {
            static::create($row);
        }
    }

    /**
     * Tempo alvo em minutos, com o divisor do modo de teste (o cadastro guarda o valor real).
     */
    public function tempoAlvoEfetivoMin(): int|float
    {
        return ModoTeste::minutos((int) $this->tempo_alvo_min);
    }
}
