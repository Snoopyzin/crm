<?php

namespace Webkul\ServiceOrder\Services\Indicators;

/**
 * Situação de um indicador contra a meta (regra do Painel do Dono, capítulo 8)
 * e os pontos que ele vale no bônus (capítulo 6.1) -- as duas regras usam os
 * mesmos limites:
 *
 * - Mín e Nota: no alvo se atinge a meta; atenção se atinge 80% dela.
 * - Teto: no alvo se fica até a meta; atenção até 1,5 × a meta.
 * - Zero: no alvo sem ocorrência; atenção com uma; abaixo com duas ou mais.
 *
 * Meta em branco ("meta da empresa" ainda não preenchida) fica sem cor.
 */
class IndicatorStatus
{
    public const VERDE = 'verde';

    public const AMARELO = 'amarelo';

    public const VERMELHO = 'vermelho';

    public const SEM_META = 'sem_meta';

    public const SEM_DADO = 'sem_dado';

    public const ROTULOS = [
        self::VERDE => 'No alvo',
        self::AMARELO => 'Atenção',
        self::VERMELHO => 'Abaixo',
        self::SEM_META => 'Meta em branco',
        self::SEM_DADO => 'Sem dado',
    ];

    public static function of(string $tipo, ?float $meta, ?float $realizado): string
    {
        if ($realizado === null) {
            return self::SEM_DADO;
        }

        if ($tipo === 'zero') {
            return match (true) {
                $realizado < 0.5 => self::VERDE,
                $realizado < 1.5 => self::AMARELO,
                default => self::VERMELHO,
            };
        }

        if ($meta === null) {
            return self::SEM_META;
        }

        if ($tipo === 'teto') {
            return match (true) {
                $realizado <= $meta => self::VERDE,
                $realizado <= $meta * 1.5 => self::AMARELO,
                default => self::VERMELHO,
            };
        }

        // min e nota
        return match (true) {
            $realizado >= $meta => self::VERDE,
            $realizado >= $meta * 0.8 => self::AMARELO,
            default => self::VERMELHO,
        };
    }

    /**
     * Fração do peso do marcador: 1 (peso inteiro), 0,5 (metade) ou 0.
     * Null = sem dado no mês: o marcador sai do cálculo.
     */
    public static function fraction(string $situacao): ?float
    {
        return match ($situacao) {
            self::VERDE => 1.0,
            self::AMARELO => 0.5,
            self::VERMELHO => 0.0,
            default => null,
        };
    }
}
