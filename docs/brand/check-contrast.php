<?php

/**
 * Checks every text/background pair in the brand directions preview against
 * WCAG AA, by script rather than by eye. The tokens and pairs are read from
 * the <script id="brand-tokens"> block in directions.html, the same data the
 * page renders its contrast tables from, and measured with the app's own
 * App\Support\ColorContrast::ratio().
 *
 * Run from the repo root: php docs/brand/check-contrast.php
 * Exits 1 if any pair is below its minimum (4.5:1 text, 3:1 non-text).
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Support\ColorContrast;

$html = file_get_contents(__DIR__.'/directions.html');
if (! preg_match('#<script type="application/json" id="brand-tokens">(.*?)</script>#s', $html, $m)) {
    fwrite(STDERR, "No brand-tokens block found in directions.html\n");
    exit(1);
}
$data = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);

// The three directions, plus the neutral page around them (header, toggle).
$groups = [];
foreach ($data['directions'] as $direction => $modes) {
    $groups[$direction] = [$modes, $data['pairs']];
}
$groups['page chrome'] = [$data['chrome'], $data['chromePairs']];

$failures = 0;
$checked = 0;
foreach ($groups as $direction => [$modes, $pairs]) {
    foreach ($modes as $mode => $tokens) {
        echo "\n{$direction} · {$mode}\n";
        foreach ($pairs as [$fg, $bg, $min, $label]) {
            if (! isset($tokens[$fg], $tokens[$bg])) {
                echo "  MISSING  {$fg} on {$bg}\n";
                $failures++;

                continue;
            }
            $ratio = ColorContrast::ratio($tokens[$fg], $tokens[$bg]);
            $ok = $ratio >= $min;
            $failures += $ok ? 0 : 1;
            $checked++;
            printf("  %s %5.2f:1 (min %.1f)  %-14s on %-16s %s\n", $ok ? 'pass' : 'FAIL', $ratio, $min, $fg, $bg, $label);
        }
    }
}

echo "\n{$checked} pairs checked, {$failures} below minimum.\n";
exit($failures > 0 ? 1 : 0);
