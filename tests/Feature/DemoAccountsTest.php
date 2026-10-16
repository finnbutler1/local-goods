<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\User;
use App\Services\DemoResetter;
use App\Services\ReviewerLinks;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function setPasswords(?string $value = 'test-only-password'): void
    {
        foreach (array_keys(config('demo.accounts')) as $role) {
            if ($role !== Role::Judge->value) {
                config(["demo.accounts.$role.password" => $value]);
            }
        }
    }

    public function test_creates_one_login_per_role_with_hashed_passwords(): void
    {
        $this->setPasswords();

        $this->artisan('demo:seed-accounts')->assertSuccessful();

        $this->assertSame(count(Role::cases()), User::count());
        foreach (Role::cases() as $role) {
            if ($role === Role::Judge) {
                continue; // the public reviewer has no known password (test below)
            }
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
        config(['demo.accounts.merchant.password' => 'test-only-password']);

        $this->artisan('demo:seed-accounts')
            ->expectsOutputToContain('Skipped bid_admin: DEMO_PASSWORD_BID_ADMIN is not set.')
            ->doesntExpectOutputToContain('test-only-password')
            ->assertSuccessful();

        // The merchant, and the public reviewer, which never needs a password.
        $this->assertEqualsCanonicalizing([Role::Merchant, Role::Judge], User::pluck('role')->all());
    }

    public function test_the_judge_account_is_the_one_public_reviewer_with_no_usable_password(): void
    {
        $this->setPasswords();
        $this->artisan('demo:seed-accounts')->assertSuccessful();

        $public = User::where('role', Role::Judge)->sole();
        $this->assertSame('Public judge access', $public->name);
        $this->assertSame(ReviewerType::Public, $public->reviewer_type);
        $this->assertTrue($public->isPublicReviewer());
        $this->assertFalse(Hash::check('test-only-password', $public->password));

        // Running again keeps its magic link working and never makes a second one.
        $link = app(ReviewerLinks::class)->newLink($public);
        $public->forceFill(['name' => 'Renamed'])->save();
        $this->artisan('demo:seed-accounts');
        $this->assertSame(1, User::where('reviewer_type', ReviewerType::Public)->count());
        $this->assertSame($public->id, app(ReviewerLinks::class)->userFor(Str::after($link, '#'))?->id);
        $this->assertSame('Public judge access', $public->fresh()->name);
    }

    public function test_the_public_reviewer_never_takes_over_another_login(): void
    {
        $merchant = User::factory()->role(Role::Merchant)->create(['email' => config('demo.accounts.judge.email')]);

        $this->artisan('demo:seed-accounts')->assertSuccessful();

        $this->assertSame(Role::Merchant, $merchant->fresh()->role);
        $this->assertNull($merchant->fresh()->reviewer_type);
    }

    public function test_running_again_updates_without_duplicating(): void
    {
        $this->setPasswords();
        $this->artisan('demo:seed-accounts');

        config(['demo.accounts.merchant.password' => 'rotated-password']);
        $this->artisan('demo:seed-accounts');

        $this->assertSame(count(Role::cases()), User::count());
        $this->assertTrue(Hash::check('rotated-password', User::where('role', Role::Merchant)->value('password')));
    }

    public function test_employee_login_is_linked_to_the_wallet_in_either_order_and_after_reset(): void
    {
        $this->setPasswords();

        (new DatabaseSeeder)->run();
        $this->artisan('demo:seed-accounts');
        $jordan = User::where('role', Role::Employee)->sole();
        $this->assertSame($jordan->id, Employee::orderBy('id')->firstOrFail()->user_id);
        $this->assertSame(1, Employee::whereNotNull('user_id')->count());

        app(DemoResetter::class)->reset();
        $this->assertSame($jordan->id, Employee::orderBy('id')->firstOrFail()->user_id);
        $this->assertSame(1, Employee::whereNotNull('user_id')->count());
        $this->assertSame(count(Role::cases()), User::count(), 'Reset demo never removes logins');
    }

    public function test_merchant_login_runs_farragut_grind_in_either_order_and_after_reset(): void
    {
        $this->setPasswords();

        $this->artisan('demo:seed-accounts');
        (new DatabaseSeeder)->run();
        $priya = User::where('role', Role::Merchant)->sole();
        $this->assertSame('Farragut Grind', $priya->merchant->name);

        app(DemoResetter::class)->reset();
        $this->assertSame('Farragut Grind', $priya->fresh()->merchant->name);
        $this->assertSame(1, Merchant::whereNotNull('user_id')->count());
    }

    public function test_role_middleware_lets_only_listed_roles_in(): void
    {
        // Judges may look at (GET) a BID admin page, but never a platform-admin-only one.
        Route::middleware(['web', 'auth', 'role:bid_admin,platform_admin'])->get('/_role-check', fn () => 'ok');
        Route::middleware(['web', 'auth', 'role:platform_admin'])->get('/_admin-check', fn () => 'ok');

        foreach (Role::cases() as $role) {
            $this->actingAs(User::factory()->role($role)->create());

            $response = $this->get('/_role-check');
            in_array($role, [Role::BidAdmin, Role::PlatformAdmin, Role::Judge], true)
                ? $response->assertOk()
                : $response->assertForbidden();

            $response = $this->get('/_admin-check');
            $role === Role::PlatformAdmin ? $response->assertOk() : $response->assertForbidden();
        }
    }

    public function test_role_cannot_be_mass_assigned(): void
    {
        $user = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'a-password', 'role' => 'platform_admin']);

        $this->assertSame(Role::Employee, $user->fresh()->role);
    }
}
