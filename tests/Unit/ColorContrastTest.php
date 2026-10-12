<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every text/background pair the design uses must pass WCAG AA (4.5:1 for
 * normal text). Colors are read from the theme tokens in resources/css/app.css,
 * so changing a token to something unreadable fails this test.
 */
class ColorContrastTest extends TestCase
{
    /** [text token, background token] pairs used in the views. */
    public const PAIRS = [
        ['ink', 'cream'], ['ink', 'paper'], ['ink', 'cream-deep'], ['ink', 'forest-tint'], ['ink', 'amber'],
        ['forest', 'cream'], ['forest', 'paper'], ['forest', 'cream-deep'], ['forest', 'amber'], ['forest', 'forest-tint'],
        ['muted', 'cream'], ['muted', 'paper'], ['muted', 'cream-deep'],
        ['cream', 'forest'], ['paper', 'forest'], ['cream', 'forest-soft'], ['sage', 'forest'], ['amber', 'forest'],
        ['amber-ink', 'cream'], ['amber-ink', 'paper'],
        ['ok', 'ok-bg'], ['hold', 'hold-bg'], ['hold', 'paper'], ['ok', 'paper'],
    ];

    /** @return array<string, string> token name => hex */
    public static function tokens(): array
    {
        preg_match_all('/--color-([a-z-]+):\s*(#[0-9a-f]{6});/i', file_get_contents(__DIR__.'/../../resources/css/app.css'), $m);

        return array_combine($m[1], array_map('strtolower', $m[2]));
    }

    public static function ratio(string $a, string $b): float
    {
        $luminance = function (string $hex): float {
            $channels = array_map(fn ($i) => hexdec(substr($hex, $i, 2)) / 255, [1, 3, 5]);
            [$r, $g, $b] = array_map(fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        };
        [$hi, $lo] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    public static function pairs(): array
    {
        return collect(self::PAIRS)->mapWithKeys(fn ($p) => ["{$p[0]} on {$p[1]}" => $p])->all();
    }

    #[DataProvider('pairs')]
    public function test_pair_passes_wcag_aa(string $text, string $background): void
    {
        $tokens = self::tokens();
        $this->assertArrayHasKey($text, $tokens);
        $this->assertArrayHasKey($background, $tokens);

        $ratio = self::ratio($tokens[$text], $tokens[$background]);
        $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('%s on %s is only %.2f:1', $text, $background, $ratio));
    }
}
