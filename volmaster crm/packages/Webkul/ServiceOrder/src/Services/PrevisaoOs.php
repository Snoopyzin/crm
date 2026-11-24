<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Previsão de conclusão da OS: a data, mais um horário opcional para o veículo
 * que entra e sai no mesmo dia.
 *
 *  - Só a data: o prazo vale até o fim do dia (como sempre valeu).
 *  - Data e horário: o prazo vence naquela hora.
 */
class PrevisaoOs
{
    protected static ?bool $disponivel = null;

    /**
     * Bancos que ainda não receberam a migration seguem funcionando só com a data.
     */
    public static function disponivel(): bool
    {
        return static::$disponivel ??= Schema::hasColumn('quotes', 'hora_previsao');
    }

    /**
     * Horário no formato HH:MM (aceita HH:MM:SS do banco). Vazio ou inválido: null.
     */
    public static function hora(mixed $hora): ?string
    {
        if (! is_string($hora) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', trim($hora), $partes)) {
            return null;
        }

        return $partes[1].':'.$partes[2];
    }

    /**
     * Instante em que o prazo vence: o horário marcado ou, sem ele, o fim do dia.
     */
    public static function momento(mixed $data, mixed $hora = null): ?Carbon
    {
        if (blank($data)) {
            return null;
        }

        $dia = Carbon::parse($data)->startOfDay();
        $hora = static::hora($hora);

        if ($hora === null) {
            return $dia->endOfDay();
        }

        [$h, $m] = explode(':', $hora);

        return $dia->setTime((int) $h, (int) $m);
    }

    /**
     * "17/11/2026" ou, com horário, "17/11/2026 às 15:30".
     */
    public static function texto(mixed $data, mixed $hora = null): ?string
    {
        if (blank($data)) {
            return null;
        }

        $texto = Carbon::parse($data)->format('d/m/Y');
        $hora = static::hora($hora);

        return $hora === null ? $texto : $texto.' às '.$hora;
    }

    /**
     * Ajusta os dados do formulário antes de gravar: sem a coluna (banco ainda
     * não migrado) o horário é descartado; sem data, não há horário.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public static function ajustar(array $dados): array
    {
        if (! static::disponivel()) {
            unset($dados['hora_previsao']);

            return $dados;
        }

        if (array_key_exists('data_previsao', $dados) && blank($dados['data_previsao'])) {
            $dados['hora_previsao'] = null;
        } elseif (array_key_exists('hora_previsao', $dados)) {
            $dados['hora_previsao'] = static::hora($dados['hora_previsao']);
        }

        return $dados;
    }
}
