<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;

/**
 * Modo de teste das barreiras (A6 do roteiro): divide os prazos por 10 para o
 * roteiro de teste não levar dias (2 h vira 12 min). Só vale com o piloto
 * ligado e é gravado por oficina (workshop_settings), então afeta apenas a
 * oficina em que foi marcado.
 *
 * Regra de ouro: o que fica gravado (regra de barreira, tipo de queixa,
 * evento, coluna da OS) é SEMPRE o prazo real. O divisor entra só onde o
 * prazo vira data (BarrierEngine), onde se decide "passou do alvo" e na
 * exibição dos cronômetros. Desligar o modo devolve o comportamento normal
 * sem tocar em dado nenhum.
 */
class ModoTeste
{
    public const DIVISOR_PADRAO = 10;

    public static function ativo(): bool
    {
        return StageGate::active() && (bool) WorkshopSettings::get('modo_teste_barreiras', false);
    }

    /**
     * Sub-opção: divide também o tempo padrão dos serviços (cronômetro do técnico).
     */
    public static function tempoPadraoAtivo(): bool
    {
        return static::ativo() && (bool) WorkshopSettings::get('modo_teste_tempo_padrao', false);
    }

    public static function divisor(): int
    {
        $valor = (int) WorkshopSettings::get('modo_teste_divisor', self::DIVISOR_PADRAO);

        return $valor >= 2 && $valor <= 100 ? $valor : self::DIVISOR_PADRAO;
    }

    /**
     * 1 com o modo desligado; o divisor com o modo ligado.
     */
    public static function fator(): int
    {
        return static::ativo() ? static::divisor() : 1;
    }

    /**
     * Minutos reais -> minutos efetivos. Desligado, devolve o próprio valor.
     */
    public static function minutos(int|float $reais): int|float
    {
        $fator = static::fator();

        return $fator === 1 ? $reais : $reais / $fator;
    }

    /**
     * $de + o prazo efetivo (relógio corrido).
     */
    public static function depois(Carbon $de, int|float $minutosReais): Carbon
    {
        return $de->copy()->addSeconds((int) round(static::minutos($minutosReais) * 60));
    }

    /**
     * $de - o prazo efetivo (relógio corrido).
     */
    public static function antes(Carbon $de, int|float $minutosReais): Carbon
    {
        return $de->copy()->subSeconds((int) round(static::minutos($minutosReais) * 60));
    }

    /**
     * "MODO DE TESTE (prazos ÷ 10)" ou null com o modo desligado.
     */
    public static function selo(): ?string
    {
        return static::ativo() ? 'MODO DE TESTE (prazos ÷ '.static::divisor().')' : null;
    }

    /**
     * 4.5 -> "4,5"; 60.0 -> "60".
     */
    public static function numero(int|float $valor): string
    {
        return rtrim(rtrim(number_format((float) $valor, 2, ',', ''), '0'), ',');
    }

    /**
     * 1440 -> "24 h"; 144 -> "2 h 24 min"; 12 -> "12 min"; 1.5 -> "1 min 30 s".
     */
    public static function duracao(int|float $minutos): string
    {
        $segundos = (int) round($minutos * 60);
        $partes = [];

        if ($horas = intdiv($segundos, 3600)) {
            $partes[] = $horas.' h';
        }

        if ($min = intdiv($segundos % 3600, 60)) {
            $partes[] = $min.' min';
        }

        if ($seg = $segundos % 60) {
            $partes[] = $seg.' s';
        }

        return $partes ? implode(' ', $partes) : '0 min';
    }
}
