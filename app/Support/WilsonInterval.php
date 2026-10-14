<?php

namespace App\Support;

/**
 * 95% Wilson score interval for a proportion such as "caught 20 of 26".
 * Unlike a plain ± margin it stays between 0% and 100% and behaves sensibly
 * for small samples, which is all an eval of a few dozen cases has.
 */
class WilsonInterval
{
    private const Z = 1.959964; // two-sided 95%

    /** @return array{0: float, 1: float}|null [low, high] in percent, or null when there is nothing to measure */
    public static function percent(int $hits, int $total): ?array
    {
        if ($total === 0) {
            return null;
        }

        $p = $hits / $total;
        $z2 = self::Z ** 2;
        $centre = ($p + $z2 / (2 * $total)) / (1 + $z2 / $total);
        $margin = self::Z * sqrt($p * (1 - $p) / $total + $z2 / (4 * $total ** 2)) / (1 + $z2 / $total);

        return [round(100 * max(0, $centre - $margin), 1), round(100 * min(1, $centre + $margin), 1)];
    }
}
