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

    public function test_everyone_gets_the_store_page_and_help_links(): void
    {
        foreach ([Role::Employee, Role::Merchant, Role::EmployerAdmin, Role::BidAdmin, Role::PlatformAdmin, Role::Judge] as $role) {
            $html = $this->menuOf($role);
            $this->assertStringContainsString(route('account.data'), $html, $role->value);
            $this->assertStringContainsString('What We Store About You', $html, $role->value);
            $this->assertStringContainsString('Help and Feedback', $html, $role->value);
            $this->assertStringContainsString(route('account').'#feedback', $html, $role->value);
        }
    }

    public function test_quick_links_fit_the_role(): void
    {
        $this->assertStringContainsString('Your Receipts', $this->menuOf(Role::Employee));
        $this->assertStringNotContainsString('Payout History</a>', $this->menuOf(Role::Employee));
        $this->assertStringContainsString('#payout-history', $this->menuOf(Role::Merchant));
        $this->assertStringNotContainsString('Your Receipts', $this->menuOf(Role::BidAdmin));
    }

    public function test_the_anchors_the_links_point_to_exist(): void
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
