<?php

namespace App\Support;

/**
 * Reads the brand color tokens from resources/css/app.css and measures WCAG
 * contrast between them. Used by the styleguide and ColorContrastTest.
 */
class ColorContrast
{
    /** [text token, background token] pairs the views are allowed to use. */
    public const PAIRS = [
        ['ink', 'cream'], ['ink', 'paper'], ['ink', 'cream-deep'], ['ink', 'forest-tint'], ['ink', 'amber'],
        ['forest', 'cream'], ['forest', 'paper'], ['forest', 'cream-deep'], ['forest', 'amber'], ['forest', 'forest-tint'],
        ['muted', 'cream'], ['muted', 'paper'], ['muted', 'cream-deep'], ['muted', 'forest-tint'],
        ['cream', 'forest'], ['paper', 'forest'], ['cream', 'forest-soft'],
        ['sage', 'forest'], ['sage', 'forest-soft'], ['amber', 'forest'],
        ['amber-ink', 'cream'], ['amber-ink', 'paper'],
        ['ok', 'ok-bg'], ['hold', 'hold-bg'], ['hold', 'paper'], ['ok', 'paper'],
    ];

    /** @return array<string, string> token name => lowercase hex */
    public static function tokens(): array
    {
        preg_match_all('/--color-([a-z-]+):\s*(#[0-9a-f]{6});/i', file_get_contents(resource_path('css/app.css')), $m);

        return array_combine($m[1], array_map('strtolower', $m[2]));
    }

    /** WCAG 2 contrast ratio between two #rrggbb colors, from 1 to 21. */
    public static function ratio(string $a, string $b): float
    {
        [$hi, $lo] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(fn ($i) => hexdec(substr($hex, $i, 2)) / 255, [1, 3, 5]);
        [$r, $g, $b] = array_map(fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
