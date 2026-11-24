<?php

namespace Webkul\ServiceOrder\Services\Indicators;

/**
 * Apresentação dos números dos indicadores: porcentagem com uma casa
 * decimal, vírgula decimal, unidade conforme o tipo (convenção do documento).
 */
class IndicatorFormatter
{
    public static function value(?float $value, string $unidade): string
    {
        if ($value === null) {
            return '—';
        }

        $number = number_format($value, 1, ',', '.');

        return match ($unidade) {
            'percent' => $number.'%',
            'days' => $number.' dias',
            'hours' => $number.' h',
            'brl' => 'R$ '.number_format($value, 2, ',', '.'),
            'count' => number_format($value, 0, ',', '.'),
            default => $number,
        };
    }

    /**
     * Meta com o sinal do tipo: "≥ 90,0%", "≤ 2,0%", "0".
     */
    public static function meta(?float $meta, string $tipo, string $unidade): string
    {
        if ($tipo === 'zero') {
            return '0';
        }

        if ($meta === null) {
            return '—';
        }

        $sinal = $tipo === 'teto' ? '≤ ' : '≥ ';

        return $sinal.self::value($meta, $unidade);
    }

    /**
     * Cor da situação para chips e cartões.
     *
     * @return array{fundo: string, texto: string}
     */
    public static function cores(string $situacao): array
    {
        return match ($situacao) {
            IndicatorStatus::VERDE => ['fundo' => '#dcfce7', 'texto' => '#166534'],
            IndicatorStatus::AMARELO => ['fundo' => '#fef3c7', 'texto' => '#92400e'],
            IndicatorStatus::VERMELHO => ['fundo' => '#fee2e2', 'texto' => '#991b1b'],
            default => ['fundo' => '#eceff1', 'texto' => '#546e7a'],
        };
    }
}
