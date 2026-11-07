<?php

namespace Tests\Feature;

use App\Support\ColorContrast;
use Tests\TestCase;

/**
 * The Wayfinding foundation (ADR 0038, docs/redesign-rules.md): self-hosted
 * Public Sans and Overpass Mono, the logo still drawn in the logo files' exact
 * colors, and the two new decorative components hidden from screen readers.
 */
class WayfindingFoundationTest extends TestCase
{
    public function test_the_new_fonts_are_self_hosted_and_the_old_ones_are_gone(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("@import '@fontsource-variable/public-sans/wght.css';", $css);
        $this->assertStringContainsString("@import '@fontsource-variable/overpass-mono/wght.css';", $css);
        $this->assertMatchesRegularExpression("/--font-sans: 'Public Sans Variable'/", $css);
        $this->assertMatchesRegularExpression("/--font-display: 'Public Sans Variable'/", $css);
        $this->assertMatchesRegularExpression("/--font-mono: 'Overpass Mono Variable'/", $css);
        $this->assertStringNotContainsString('Atkinson', $css);
        $this->assertStringNotContainsString('Overpass Variable', $css);
        $this->assertDoesNotMatchRegularExpression('#https?://#', $css, 'No outside font or image services.');

        $packages = json_decode(file_get_contents(base_path('package.json')), true)['devDependencies'];
        $this->assertArrayHasKey('@fontsource-variable/public-sans', $packages);
        $this->assertArrayHasKey('@fontsource-variable/overpass-mono', $packages);
        $this->assertArrayNotHasKey('@fontsource-variable/overpass', $packages);
        $this->assertArrayNotHasKey('@fontsource-variable/atkinson-hyperlegible-next', $packages);
    }

    public function test_the_logo_keeps_the_logo_files_exact_colors(): void
    {
        $light = ColorContrast::tokens('light');
        $dark = ColorContrast::tokens('dark');
        $file = fn (string $name) => file_get_contents(base_path("docs/brand/transit/{$name}"));

        // <x-logo> draws the line, stops and wordmark with these tokens.
        foreach (['primary' => '#0a6b67', 'ink' => '#121820', 'accent' => '#e2603a'] as $token => $hex) {
            $this->assertSame($hex, $light[$token], $token);
            $this->assertStringContainsStringIgnoringCase($hex, $file('logo-a.svg'), $token);
        }
        foreach (['primary' => '#42c4b9', 'ink' => '#edf1f4', 'accent' => '#ff8a63'] as $token => $hex) {
            $this->assertSame($hex, $dark[$token], "dark {$token}");
            $this->assertSame($hex, $light["night-{$token}"] ?? $light['on-night'], "night {$token}");
            $this->assertStringContainsStringIgnoringCase($hex, $file('logo-a-dark.svg'), $token);
        }
    }

    public function test_stop_numbers_are_hidden_from_screen_readers(): void
    {
        $html = (string) $this->blade('<x-stop-number n="2" />');

        $this->assertMatchesRegularExpression('#<span class="stop-number" aria-hidden="true">2</span>#', $html);
    }

    public function test_a_station_sign_is_a_real_heading_with_decorative_dots(): void
    {
        $html = (string) $this->blade('<x-station-sign as="h2" :dots="3" :local="2">How it works</x-station-sign>');

        $this->assertMatchesRegularExpression('#<h2 class="station-sign-label">How it works</h2>#', $html);
        $this->assertMatchesRegularExpression('#<span class="station-sign-dots" aria-hidden="true">#', $html);
        $this->assertSame(1, substr_count($html, 'is-local'));

        $plain = (string) $this->blade('<x-station-sign>Where the money is</x-station-sign>');
        $this->assertStringContainsString('<h2 class="station-sign-label">Where the money is</h2>', $plain);
        $this->assertStringNotContainsString('station-sign-dots', $plain);
    }
}
