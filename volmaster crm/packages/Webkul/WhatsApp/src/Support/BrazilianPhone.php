<?php

namespace Webkul\WhatsApp\Support;

/**
 * Brazilian phone numbers: shown as "+55 (62) 99999-9999" everywhere, sent
 * to the WhatsApp API as "5562999999999".
 */
class BrazilianPhone
{
    /**
     * National digits (DDD + number, no country code), or '' if empty.
     * Accepts any common way of typing it: (62) 99999-9999, 062 9..., +55..., 5562...
     */
    public static function national(?string $phone): string
    {
        $digits = ltrim(preg_replace('/\D/', '', (string) $phone), '0');

        // 12-13 digits starting with 55 = country code already included.
        // (11 digits starting with 55 is DDD 55 -- Rio Grande do Sul.)
        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    /**
     * "+55 (62) 99999-9999" / "+55 (62) 3333-4444"; anything that doesn't
     * look like DDD + 8/9 digits is returned as typed.
     */
    public static function format(?string $phone): string
    {
        $national = self::national($phone);

        if (! preg_match('/^(\d{2})(\d{4,5})(\d{4})$/', $national, $parts)) {
            return trim((string) $phone);
        }

        return "+55 ({$parts[1]}) {$parts[2]}-{$parts[3]}";
    }

    /**
     * "5562999999999" for the WhatsApp Cloud API, or null when the number
     * can't be a valid Brazilian phone (needs DDD + 8 or 9 digits).
     */
    public static function e164(?string $phone): ?string
    {
        $national = self::national($phone);

        return preg_match('/^\d{10,11}$/', $national) ? '55'.$national : null;
    }
}
