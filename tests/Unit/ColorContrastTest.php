<?php

namespace Tests\Unit;

use App\Support\ColorContrast;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every text/background pair the design uses must pass WCAG AA (4.5:1 for
 * normal text). Colors are read from the theme tokens in resources/css/app.css,
 * so changing a token to something unreadable fails this test.
 */
class ColorContrastTest extends TestCase
{
    public static function pairs(): array
    {
        return collect(ColorContrast::PAIRS)->mapWithKeys(fn ($p) => ["{$p[0]} on {$p[1]}" => $p])->all();
    }

    #[DataProvider('pairs')]
    public function test_pair_passes_wcag_aa(string $text, string $background): void
    {
        $tokens = ColorContrast::tokens();
        $this->assertArrayHasKey($text, $tokens);
        $this->assertArrayHasKey($background, $tokens);

        $ratio = ColorContrast::ratio($tokens[$text], $tokens[$background]);
        $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('%s on %s is only %.2f:1', $text, $background, $ratio));
    }

    public function test_ratio_matches_known_values(): void
    {
        $this->assertEqualsWithDelta(21.0, ColorContrast::ratio('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, ColorContrast::ratio('#1f3d2b', '#1f3d2b'), 0.01);
    }
}
