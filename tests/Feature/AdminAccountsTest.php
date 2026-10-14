<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Accounts table on the platform admin's home page. */
class AdminAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function revokedReviewer(): User
    {
        $user = User::factory()->role(Role::Judge)->create(['name' => 'Morgan Revoked']);
        $user->forceFill(['revoked_at' => now()])->save();

        return $user;
    }

    public function test_each_account_shows_its_status_and_revoked_ones_are_hidden_by_default(): void
    {
        $this->revokedReviewer();
        User::factory()->role(Role::Merchant)->create(['name' => 'Avery Active']);
        $this->signIn(Role::PlatformAdmin);

        $this->get('/admin')->assertOk()
            ->assertSee('Status')
            ->assertSeeInOrder(['Avery Active', 'Merchant', 'Active'])
            ->assertDontSee('Morgan Revoked')
            ->assertSee('Show revoked (1)')
            ->assertSee('href="'.route('admin.dashboard', ['revoked' => 1]).'"', false);
    }

    public function test_show_revoked_lists_them_with_a_revoked_status(): void
    {
        $this->revokedReviewer();
        $this->signIn(Role::PlatformAdmin);

        $this->get('/admin?revoked=1')->assertOk()
            ->assertSeeInOrder(['Morgan Revoked', 'Judge', 'Revoked'])
            ->assertSee('Hide revoked')
            ->assertSee('href="'.route('admin.dashboard').'"', false);
    }

    public function test_no_toggle_when_nothing_is_revoked(): void
    {
        $this->signIn(Role::PlatformAdmin);

        $this->get('/admin')->assertOk()->assertDontSee('Show revoked');
    }

    public function test_only_the_platform_admin_sees_accounts(): void
    {
        $this->signIn(Role::BidAdmin);
        $this->get('/admin?revoked=1')->assertForbidden();
    }
}
