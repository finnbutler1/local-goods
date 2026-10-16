<?php

namespace App\Support;

class Money
{
    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, 2);
    }

    /**
     * "12.5" becomes 1250. Exact (string arithmetic, no floats). Expects a
     * validated amount: digits with up to two decimals.
     */
    public static function toCents(string $dollars): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($dollars), 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /** 1250 becomes "12.50", for prefilling a dollars field. */
    public static function toDollars(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
