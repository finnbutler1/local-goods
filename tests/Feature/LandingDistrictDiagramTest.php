<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The landing hero's transit diagram (ADR 0038): drawn from the stored
 * boundary, the demo merchants labelled as demo, one coral local stop, a
 * text alternative for screen readers, and one draw-in that only runs when
 * motion is allowed.
 */
class LandingDistrictDiagramTest extends TestCase
{
    use RefreshDatabase;

    private function hero(): string
    {
        return str($this->get('/')->assertOk()->getContent())->after('aria-labelledby="hero-heading"')->before('id="the-problem"')->toString();
    }

    public function test_the_map_is_one_labelled_image_of_the_demo_merchants(): void
    {
        $hero = $this->hero();

        $this->assertSame(1, preg_match_all('/<svg[^>]*role="img"[^>]*data-district-diagram/', $hero));
        $this->assertStringContainsString('Map of the Golden Triangle district: 8 demo merchants as stops on one line', $hero);
        $this->assertStringContainsString('Demo merchants</li>', $hero);
        $this->assertStringContainsString('District boundary</li>', $hero);
        $this->assertSame(8, substr_count($hero, 'data-stop="'));
        $this->assertSame(1, substr_count($hero, 'data-local-stop'));
        $this->assertMatchesRegularExpression('/data-stop="Farragut Grind"\s+data-local-stop/', $hero);
        $this->assertStringNotContainsString('http', str($hero)->after('<svg')->before('</svg>')->toString());
    }

    public function test_screen_readers_get_every_merchant_and_the_labels_are_decoration(): void
    {
        $hero = $this->hero();
        $list = str($hero)->after('aria-label="Demo merchants on the map"')->before('</ul>')->toString();

        foreach (DatabaseSeeder::DEMO_MERCHANTS as [$name]) {
            $this->assertStringContainsString(e($name), $list);
        }
        $this->assertStringContainsString('Farragut Grind, local stop', preg_replace('/\s+/', ' ', $list));
        preg_match_all('/<text class="([^"]+)"[^>]*>/', $hero, $texts, PREG_SET_ORDER);
        $this->assertNotEmpty($texts);
        foreach ($texts as [$tag]) {
            $this->assertStringContainsString('aria-hidden="true"', $tag);
        }
    }

    public function test_the_wallet_card_has_no_shadow_and_the_stop_numbers_are_hidden(): void
    {
        $html = $this->get('/')->getContent();
        $card = str($html)->after('class="fare-card"')->before('AI fraud check passed')->toString();

        $this->assertStringNotContainsString('shadow', $card);
        preg_match_all('/<span class="stop-number"[^>]*>(\d)<\/span>/', $html, $stops, PREG_SET_ORDER);
        $this->assertSame(['1', '2', '3', '4', '5', '6', '7'], array_column($stops, 1));
        foreach ($stops as [$tag]) {
            $this->assertStringContainsString('aria-hidden="true"', $tag);
        }
    }

    public function test_the_route_draws_in_only_when_motion_is_allowed(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $allowed = str($css)->after('@media (prefers-reduced-motion: no-preference) {')->before('/* Phones:')->toString();

        $this->assertStringContainsString('animation: dd-draw', $allowed);
        $this->assertSame(1, substr_count($css, 'animation: dd-draw'));
        $this->assertSame(1, substr_count($css, 'stroke-dashoffset: 1;'));
    }
}
