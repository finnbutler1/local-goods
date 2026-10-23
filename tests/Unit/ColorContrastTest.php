<?php

namespace Tests\Unit;

use App\Support\ColorContrast;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every text/background pair the design uses must pass WCAG AA in light and
 * dark mode (4.5:1 for text, 3:1 for focus rings and control edges). Colors
 * are read from the theme tokens in resources/css/app.css, so changing a
 * token to something unreadable fails this test.
 */
class ColorContrastTest extends TestCase
{
    public static function pairs(): array
    {
        return collect(['light', 'dark'])->flatMap(fn ($mode) => collect(ColorContrast::PAIRS)
            ->mapWithKeys(fn ($p) => ["{$p[0]} on {$p[1]} ({$mode})" => [$mode, $p[0], $p[1], $p[2] ?? 4.5]]))->all();
    }

    #[DataProvider('pairs')]
    public function test_pair_passes_wcag_aa(string $mode, string $text, string $background, float $minimum): void
    {
        $tokens = ColorContrast::tokens($mode);
        $this->assertArrayHasKey($text, $tokens);
        $this->assertArrayHasKey($background, $tokens);

        $ratio = ColorContrast::ratio($tokens[$text], $tokens[$background]);
        $this->assertGreaterThanOrEqual($minimum, $ratio, sprintf('%s on %s (%s) is only %.2f:1', $text, $background, $mode, $ratio));
    }

    public function test_dark_mode_redefines_every_color_that_changes_with_the_theme(): void
    {
        $light = ColorContrast::tokens('light');
        $dark = ColorContrast::tokens('dark');
        $this->assertSame(array_keys($light), array_keys($dark));
        foreach (['page', 'surface', 'ink', 'muted', 'primary', 'on-primary', 'accent', 'focus'] as $name) {
            $this->assertNotSame($light[$name], $dark[$name], "{$name} should change in dark mode");
        }
    }

    public function test_ratio_matches_known_values(): void
    {
        $this->assertEqualsWithDelta(21.0, ColorContrast::ratio('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, ColorContrast::ratio('#1f3d2b', '#1f3d2b'), 0.01);
    }
}
