<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Money fields are shown and typed as "R$ 10.000,00" (see the
 * service_order::components.money-mask script). Before validation, turn any
 * submitted value in exactly that shape back into a plain decimal
 * ("10000.00") so every controller/rule keeps working unchanged. Only values
 * starting with "R$" are touched, so unrelated fields (tokens, text) are safe.
 */
class NormalizeMoneyInput
{
    protected const PATTERN = '/^\s*-?\s*R\$\s*-?[\d.]*(,\d{0,2})?\s*$/u';

    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            $request->merge($this->normalize($request->request->all()));
        }

        return $next($request);
    }

    protected function normalize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalize($value);
            } elseif (is_string($value) && preg_match(self::PATTERN, $value)) {
                $negative = str_contains($value, '-');

                $number = str_replace(['R$', ' ', "\u{00A0}", '-', '.'], '', $value);
                $number = str_replace(',', '.', $number);

                $data[$key] = $number === '' || $number === '.' ? '' : ($negative ? '-' : '').number_format((float) $number, 2, '.', '');
            }
        }

        return $data;
    }
}
