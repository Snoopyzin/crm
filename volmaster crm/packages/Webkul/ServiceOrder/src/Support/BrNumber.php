<?php

namespace Webkul\ServiceOrder\Support;

/**
 * Número digitado no formato brasileiro: "R$ 10.000,50", "2.000", "12,5",
 * "300". Vazio ou texto inválido vira null.
 */
class BrNumber
{
    public static function parse(mixed $bruto): ?float
    {
        if (is_int($bruto) || is_float($bruto)) {
            return (float) $bruto;
        }

        $texto = trim(str_replace(['R$', '%', ' '], '', (string) $bruto));

        if ($texto === '') {
            return null;
        }

        if (str_contains($texto, ',')) {
            // "10.000,50": o ponto é milhar e a vírgula é decimal.
            $texto = str_replace(',', '.', str_replace('.', '', $texto));
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $texto)) {
            // "2.000" ou "1.250.000": só milhares.
            $texto = str_replace('.', '', $texto);
        }

        return is_numeric($texto) ? (float) $texto : null;
    }
}
