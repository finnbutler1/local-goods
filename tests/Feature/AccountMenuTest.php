<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountMenuTest extends TestCase
{
    use RefreshDatabase;

    private function menuOf(Role $role): string
    {
        $user = User::factory()->role($role)->create();
        $html = $this->actingAs($user)->get('/account')->assertOk()->getContent();

        return $html;
    }

    public function test_everyone_gets_the_account_and_store_pages_and_log_out(): void
    {
        foreach ([Role::Employee, Role::Merchant, Role::EmployerAdmin, Role::BidAdmin, Role::PlatformAdmin, Role::Judge] as $role) {
            $html = $this->menuOf($role);
            $this->assertStringContainsString(route('account.data'), $html, $role->value);
            $this->assertStringContainsString('Your Account', $html, $role->value);
            $this->assertStringContainsString('What We Store About You', $html, $role->value);
            $this->assertStringContainsString('Log Out', $html, $role->value);
        }
    }

    public function test_the_trimmed_account_menu_items_are_gone_for_every_role(): void
    {
        // Removed from the menu only: the pages and anchors they pointed at stay (next test),
        // and the floating Feedback button still reaches the feedback form.
        foreach ([Role::Employee, Role::Merchant, Role::EmployerAdmin, Role::BidAdmin, Role::PlatformAdmin, Role::Judge] as $role) {
            $html = $this->menuOf($role);
            foreach (['Your Receipts', 'Payout History', 'Back to the Public Site', 'Help and Feedback'] as $gone) {
                $this->assertStringNotContainsString($gone, $html, "{$role->value}: {$gone}");
            }
            $this->assertStringContainsString('Feedback', $html, "{$role->value}: the Feedback button stays");
        }
    }

    public function test_only_the_employee_gets_demo_tools(): void
    {
        $this->assertStringContainsString('Demo Tools', $this->menuOf(Role::Employee));
        $this->assertStringNotContainsString('Demo Tools', $this->menuOf(Role::Merchant));
        $this->assertStringNotContainsString('Demo Tools', $this->menuOf(Role::BidAdmin));
    }

    public function test_the_pages_the_removed_links_pointed_to_still_exist(): void
    {
        $this->assertStringContainsString('id="feedback"', $this->actingAs(User::factory()->role(Role::Employee)->create())->get('/account')->getContent());
        $this->assertStringContainsString('id="payout-history"', file_get_contents(resource_path('views/dashboards/merchant.blade.php')));
        $this->assertStringContainsString('id="history"', file_get_contents(resource_path('views/employee/wallet.blade.php')));
    }

    public function test_the_store_page_opens_for_every_role_and_a_judge_sees_the_link_section(): void
    {
        foreach ([Role::Employee, Role::Merchant, Role::EmployerAdmin, Role::BidAdmin, Role::PlatformAdmin] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get('/account/data')
                ->assertOk()->assertSee('What We Store About You')->assertDontSee('Your magic link');
        }

        $this->actingAs(User::factory()->role(Role::Judge)->create())->get('/account/data')
            ->assertOk()->assertSee('Your magic link')->assertSee('hash')->assertSee('Votes in the independent check');
    }

    public function test_the_store_page_is_a_get_only_page(): void
    {
        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('account.data')->methods());
    }

    public function test_it_needs_a_login(): void
    {
        $this->get('/account/data')->assertRedirect('/login');
    }
}
