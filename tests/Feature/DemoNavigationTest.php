<?php

namespace Tests\Feature;

use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar shows only the pages a role may open, and the old pre-login
 * /demo links send people to Log in.
 */
class DemoNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_sidebar_shows_only_the_roles_own_pages(): void
    {
        foreach (Role::cases() as $role) {
            $this->signIn($role);
            $html = $this->get(route($role->dashboardRoute()))->assertOk()->getContent();

            foreach ($role->navigation() as $item) {
                $this->assertStringContainsString('href="'.route($item['route']).'"', $html);
            }
            if ($role !== Role::Employee) {
                $this->assertStringNotContainsString('href="'.route('employee.wallet').'"', $html, $role->value);
            }
            if (! in_array($role, [Role::BidAdmin, Role::PlatformAdmin], true)) {
                $this->assertStringNotContainsString('href="'.route('bid-admin.dashboard').'"', $html, $role->value);
            }
        }
    }

    public function test_old_demo_urls_redirect_to_log_in(): void
    {
        foreach (['/demo/employee', '/demo/employee?suspicious=1', '/demo/bid-admin', '/demo/bid-admin/eval'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_eval_results_stay_public(): void
    {
        $this->get('/eval')
            ->assertOk()
            ->assertSee('How often does the AI agree with a person?')
            ->assertDontSee('aria-label="App"', false);
    }
}
