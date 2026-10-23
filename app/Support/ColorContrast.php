<?php

namespace App\Support;

/**
 * Reads the brand color tokens (light and dark) from resources/css/app.css and
 * measures WCAG contrast between them. Used by the styleguide and ColorContrastTest.
 */
class ColorContrast
{
    /**
     * [text token, background token, minimum ratio] pairs the views may use,
     * checked in light and dark. 4.5:1 for text; 3:1 for focus rings and
     * control edges (WCAG 1.4.11).
     */
    public const PAIRS = [
        ['ink', 'page'], ['ink', 'surface'], ['ink', 'band'], ['ink', 'primary-tint'],
        ['muted', 'page'], ['muted', 'surface'], ['muted', 'band'], ['muted', 'primary-tint'],
        ['primary', 'page'], ['primary', 'surface'], ['primary', 'band'], ['primary', 'primary-tint'],
        ['on-primary', 'primary'], ['on-primary', 'primary-hover'], ['on-primary', 'primary-pressed'],
        ['on-accent', 'accent'], ['accent-ink', 'page'], ['accent-ink', 'surface'], ['accent-ink', 'band'],
        ['ok', 'ok-bg'], ['ok', 'surface'], ['ok', 'page'],
        ['hold', 'hold-bg'], ['hold', 'surface'], ['hold', 'page'],
        ['danger', 'danger-bg'], ['danger', 'surface'], ['danger', 'page'],
        ['disabled-ink', 'disabled-bg'],
        ['on-night', 'night'], ['on-night', 'night-soft'], ['night-muted', 'night'], ['night-muted', 'night-soft'],
        ['night-primary', 'night'], ['night-primary', 'night-soft'], ['night-accent', 'night'],
        ['focus', 'page', 3.0], ['focus', 'surface', 3.0], ['night-focus', 'night', 3.0],
        ['edge', 'surface', 3.0], ['edge', 'page', 3.0], ['primary', 'page', 3.0],
    ];

    /**
     * @return array<string, string> token name => lowercase hex, for 'light'
     *                               (the @theme block) or 'dark' (light with the
     *                               :root[data-theme='dark'] block on top)
     */
    public static function tokens(string $mode = 'light'): array
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $light = self::hexes(self::block($css, '@theme {'));

        return $mode === 'dark'
            ? array_merge($light, self::hexes(self::block($css, ":root[data-theme='dark'] {")))
            : $light;
    }

    /** WCAG 2 contrast ratio between two #rrggbb colors, from 1 to 21. */
    public static function ratio(string $a, string $b): float
    {
        [$hi, $lo] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function block(string $css, string $opening): string
    {
        $start = strpos($css, $opening);

        return $start === false ? '' : substr($css, $start, strpos($css, '}', $start) - $start);
    }

    /** @return array<string, string> */
    private static function hexes(string $block): array
    {
        preg_match_all('/--color-([a-z0-9-]+):\s*(#[0-9a-f]{6});/i', $block, $m);

        return array_combine($m[1], array_map('strtolower', $m[2]));
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(fn ($i) => hexdec(substr($hex, $i, 2)) / 255, [1, 3, 5]);
        [$r, $g, $b] = array_map(fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
