<?php

namespace App\Support;

class Money
{
    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, 2);
    }
}
