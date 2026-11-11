<?php

/**
 * Prints the 95% Wilson confidence intervals quoted in the README ("How the AI
 * was measured"). Read-only: it reads no eval data and writes nothing. The
 * counts are the published holdout results; change them here only when the
 * results pages change.
 *
 *   php scripts/eval-intervals.php
 *
 * Exits 1 if its own formula disagrees with App\Support\WilsonInterval.
 */

require __DIR__.'/../vendor/autoload.php';

function wilson(int $hits, int $total): array
{
    $z = 1.959964;
    $p = $hits / $total;
    $centre = ($p + $z ** 2 / (2 * $total)) / (1 + $z ** 2 / $total);
    $margin = $z * sqrt($p * (1 - $p) / $total + $z ** 2 / (4 * $total ** 2)) / (1 + $z ** 2 / $total);

    return [round(100 * max(0, $centre - $margin), 1), round(100 * min(1, $centre + $margin), 1)];
}

$rows = [
    ['Fraud: suspicious cases caught', 17, 26],
    ['Fraud: holds that were suspicious', 17, 19],
    ['Fraud: agreement with the person', 39, 50],
    ['Merchant: agreement with the labels', 17, 20],
    ['Merchant: always "not eligible" baseline', 17, 20],
    ['Merchant: independents found', 0, 3],
    ['Merchant: chains wrongly called eligible', 0, 17],
];

$ok = true;
foreach ($rows as [$label, $hits, $total]) {
    [$low, $high] = wilson($hits, $total);
    $app = App\Support\WilsonInterval::percent($hits, $total);
    $same = $app === [$low, $high];
    $ok = $ok && $same;
    printf("%-44s %2d of %-3d %5.1f%% to %5.1f%%%s\n", $label, $hits, $total, $low, $high, $same ? '' : '  MISMATCH with WilsonInterval');
}

exit($ok ? 0 : 1);
