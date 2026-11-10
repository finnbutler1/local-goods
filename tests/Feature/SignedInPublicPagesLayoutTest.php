<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pages that are public but that signed-in people open from the menu keep
 * the signed-in layout (sidebar, menu highlight) for them, and the public
 * layout for everyone logged out.
 */
class SignedInPublicPagesLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['/district', '/eval/merchants', '/eval', '/projection', '/who-does-what'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        DemoAccountSeeder::seedPublicReviewer(config('demo.accounts.judge'));
        $this->withoutVite();
    }

    /** @return array<string, User> */
    private function viewers(): array
    {
        $named = User::factory()->role(Role::Judge)->create(['name' => 'Avery Example']);
        $named->forceFill(['reviewer_type' => ReviewerType::ContestJudge])->save();

        return [
            'public judge' => User::where('reviewer_type', ReviewerType::Public)->sole(),
            'named judge' => $named,
            'BID admin' => User::factory()->role(Role::BidAdmin)->create(),
            'platform admin' => User::factory()->role(Role::PlatformAdmin)->create(),
        ];
    }

    public function test_signed_in_people_get_the_sidebar_on_each_page(): void
    {
        foreach ($this->viewers() as $who => $user) {
            foreach (self::PAGES as $url) {
                $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
                $this->assertStringContainsString('<aside', $html, "$who on $url");
                $this->assertStringContainsString('aria-label="App"', $html, "$who on $url");
                $this->assertStringContainsString('<main id="main"', $html);
            }
        }
    }

    public function test_the_menu_highlights_the_page_for_admins(): void
    {
        foreach (['BID admin', 'platform admin'] as $who) {
            $user = $this->viewers()[$who];
            foreach (['/district' => 'District Map', '/eval/merchants' => 'Merchant Eval'] as $url => $label) {
                $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
                preg_match('#<aside.*?</aside>#s', $html, $aside);
                $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>.*?'.preg_quote($label, '/').'/s', $aside[0] ?? '', "$who on $url");
            }
        }
    }

    public function test_logged_out_visitors_keep_the_public_layout(): void
    {
        foreach (self::PAGES as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<aside', $html, $url);
            $this->assertStringNotContainsString('aria-label="App"', $html, $url);
            $this->assertStringContainsString('aria-label="Site"', $html, $url);
            $this->assertStringContainsString('Not affiliated with the Golden Triangle BID.', $html, $url);
            $this->assertStringContainsString('Log in', $html, $url);
        }
    }

    public function test_judges_get_no_write_forms_on_these_pages(): void
    {
        foreach (['public judge', 'named judge'] as $who) {
            foreach (self::PAGES as $url) {
                $html = $this->actingAs($this->viewers()[$who])->get($url)->assertOk()->getContent();
                // Only the shell's own POST forms (log out, feedback) may exist.
                preg_match_all('#<form\\b[^>]*>#i', $html, $forms);
                foreach ($forms[0] as $form) {
                    if (preg_match('/method="post"/i', $form)) {
                        $this->assertMatchesRegularExpression('/logout|feedback/', $form, "$who on $url: $form");
                    }
                }
            }
        }
    }
}
