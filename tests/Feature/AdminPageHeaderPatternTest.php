<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The admin pages use the redesigned header: station sign, page-title, lede. */
class AdminPageHeaderPatternTest extends TestCase
{
    use RefreshDatabase;

    public function test_these_pages_use_the_station_sign_header_not_the_old_one(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->withoutVite();
        $admin = User::factory()->role(Role::PlatformAdmin)->create();

        foreach (['/bid-admin/fees', '/bid-admin/demo-activity', '/admin/reconciliation', '/admin/changelog', '/admin/feedback', '/admin/reviewers', '/admin/accounts'] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<div class="station-sign">\s*<p class="station-sign-label">/', $html, $url);
            $this->assertStringContainsString('<h1 class="page-title">', $html, $url);
            $this->assertStringContainsString('class="page-lede"', $html, $url);
            $this->assertStringNotContainsString('text-title font-semibold text-primary', $html, $url);
        }
    }
}
