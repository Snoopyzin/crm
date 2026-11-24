<?php

namespace Webkul\ServiceOrder\Support;

/**
 * Validação de CPF e CNPJ pelos dígitos verificadores (B2 do roteiro).
 */
class Documento
{
    public static function digitos(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }

    /**
     * CPF (11 dígitos) ou CNPJ (14 dígitos) com os dígitos verificadores corretos.
     */
    public static function valido(?string $valor): bool
    {
        $digitos = static::digitos($valor);

        return match (strlen($digitos)) {
            11 => static::cpfValido($digitos),
            14 => static::cnpjValido($digitos),
            default => false,
        };
    }

    public static function cpfValido(string $cpf): bool
    {
        $cpf = static::digitos($cpf);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($posicao = 9; $posicao < 11; $posicao++) {
            $soma = 0;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cpf[$i] * ($posicao + 1 - $i);
            }

            $digito = ((10 * $soma) % 11) % 10;

            if ((int) $cpf[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }

    public static function cnpjValido(string $cnpj): bool
    {
        $cnpj = static::digitos($cnpj);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        foreach ([12 => [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], 13 => [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $posicao => $pesos) {
            $soma = 0;

            foreach ($pesos as $i => $peso) {
                $soma += (int) $cnpj[$i] * $peso;
            }

            $resto = $soma % 11;
            $digito = $resto < 2 ? 0 : 11 - $resto;

            if ((int) $cnpj[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }
}
