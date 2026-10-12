<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use App\Services\DemoResetter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DemoAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function setPasswords(?string $value = 'test-only-password'): void
    {
        foreach (array_keys(config('demo.accounts')) as $role) {
            config(["demo.accounts.$role.password" => $value]);
        }
    }

    public function test_creates_one_login_per_role_with_hashed_passwords(): void
    {
        $this->setPasswords();

        $this->artisan('demo:seed-accounts')->assertSuccessful();

        $this->assertSame(count(Role::cases()), User::count());
        foreach (Role::cases() as $role) {
            $user = User::where('role', $role)->sole();
            $this->assertNotSame('test-only-password', $user->password);
            $this->assertTrue(Hash::check('test-only-password', $user->password));
        }
        $this->assertSame('Sam Taylor', User::where('role', Role::BidAdmin)->value('name'));
        $this->assertSame('Jordan Rivera', User::where('role', Role::Employee)->value('name'));
    }

    public function test_skips_accounts_without_a_password_and_never_prints_one(): void
    {
        $this->setPasswords(null);
        config(['demo.accounts.judge.password' => 'test-only-password']);

        $this->artisan('demo:seed-accounts')
            ->expectsOutputToContain('Skipped bid_admin: DEMO_PASSWORD_BID_ADMIN is not set.')
            ->doesntExpectOutputToContain('test-only-password')
            ->assertSuccessful();

        $this->assertSame([Role::Judge], User::pluck('role')->all());
    }

    public function test_running_again_updates_without_duplicating(): void
    {
        $this->setPasswords();
        $this->artisan('demo:seed-accounts');

        config(['demo.accounts.judge.password' => 'rotated-password']);
        $this->artisan('demo:seed-accounts');

        $this->assertSame(count(Role::cases()), User::count());
        $this->assertTrue(Hash::check('rotated-password', User::where('role', Role::Judge)->value('password')));
    }

    public function test_employee_login_is_linked_to_the_wallet_in_either_order_and_after_reset(): void
    {
        $this->setPasswords();

        (new DatabaseSeeder)->run();
        $this->artisan('demo:seed-accounts');
        $jordan = User::where('role', Role::Employee)->sole();
        $this->assertSame($jordan->id, Employee::sole()->user_id);

        app(DemoResetter::class)->reset();
        $this->assertSame($jordan->id, Employee::sole()->user_id);
        $this->assertSame(count(Role::cases()), User::count(), 'Reset demo never removes logins');
    }

    public function test_role_middleware_lets_only_listed_roles_in(): void
    {
        Route::middleware(['web', 'auth', 'role:bid_admin,platform_admin'])->get('/_role-check', fn () => 'ok');

        foreach (Role::cases() as $role) {
            $response = $this->actingAs(User::factory()->role($role)->create())->get('/_role-check');
            in_array($role, [Role::BidAdmin, Role::PlatformAdmin], true)
                ? $response->assertOk()
                : $response->assertForbidden();
        }
    }

    public function test_role_cannot_be_mass_assigned(): void
    {
        $user = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'a-password', 'role' => 'platform_admin']);

        $this->assertSame(Role::Employee, $user->fresh()->role);
    }
}
