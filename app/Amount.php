<?php

namespace App;

/**
 * Formats money amounts for display.
 *
 * Amounts are shown with at least two decimals. When the stored value has
 * significant digits beyond two decimals (e.g. a 3-decimal currency), the
 * full precision is kept; otherwise trailing zeros are trimmed down to two.
 */
final class Amount
{
    /**
     * @param  string|int|float  $value  A numeric amount.
     * @return string Amount such as "12.00", "12.50" or "12.3456".
     */
    public static function display(string|int|float $value): string
    {
        $string = (string) $value;
        $negative = str_starts_with($string, '-');
        $abs = $negative ? substr($string, 1) : $string;

        $clean = rtrim(rtrim($abs, '0'), '.');

        if ($clean === '') {
            $clean = '0';
        }

        $position = strpos($clean, '.');
        $significantDecimals = $position === false ? 0 : strlen(substr($clean, $position + 1));
        $decimals = max(2, $significantDecimals);

        $formatted = number_format((float) $clean, $decimals, '.', '');

        return $negative ? '-'.$formatted : $formatted;
    }
}
