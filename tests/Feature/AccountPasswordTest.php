<?php

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Enums\Role;
use App\Models\User;
use App\Support\DemoLogins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_change_their_own_password(): void
    {
        $admin = User::factory()->role(Role::BidAdmin)->create(['password' => 'old-password-123']);
        $this->actingAs($admin);

        $this->get('/account')->assertOk()->assertSee('Change your password')->assertSee($admin->email);

        $this->from('/account')->put('/user/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertRedirect('/account')->assertSessionHas('status', 'password-updated');

        $this->assertTrue(Hash::check('new-password-456', $admin->fresh()->password));
        $this->assertSame(Role::BidAdmin, $admin->fresh()->role);
    }

    public function test_the_current_password_is_required(): void
    {
        $admin = User::factory()->role(Role::BidAdmin)->create(['password' => 'old-password-123']);
        $this->actingAs($admin);

        $this->from('/account')->put('/user/password', [
            'current_password' => 'wrong',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertSessionHasErrors('current_password', errorBag: 'updatePassword');

        $this->assertTrue(Hash::check('old-password-123', $admin->fresh()->password));
    }

    public function test_the_password_form_only_touches_the_signed_in_user(): void
    {
        $admin = User::factory()->role(Role::BidAdmin)->create(['password' => 'old-password-123']);
        $other = User::factory()->role(Role::PlatformAdmin)->create(['password' => 'other-password-1']);
        $this->actingAs($admin);

        $this->put('/user/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
            'email' => $other->email,
            'role' => 'platform_admin',
        ]);

        $this->assertTrue(Hash::check('other-password-1', $other->fresh()->password));
        $this->assertSame(Role::BidAdmin, $admin->fresh()->role);
    }

    public static function demoLogins(): array
    {
        return [
            'BID admin' => ['bid_admin', Role::BidAdmin],
            'employer' => ['employer_admin', Role::EmployerAdmin],
            'employee' => ['employee', Role::Employee],
            'merchant' => ['merchant', Role::Merchant],
        ];
    }

    #[DataProvider('demoLogins')]
    public function test_a_shared_demo_login_cannot_change_its_password(string $key, Role $role): void
    {
        $user = User::factory()->role($role)->create([
            'email' => config("demo.accounts.$key.email"),
            'password' => 'old-password-123',
        ]);
        $this->actingAs($user);

        $this->get('/account')->assertOk()
            ->assertSee('This is a shared demo login, so its password can\'t be changed.', false)
            ->assertDontSee('Change your password')
            ->assertDontSee(route('user-password.update'), false);

        $this->from('/account')->put('/user/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertRedirect('/account')
            ->assertSessionHasErrors(['current_password' => DemoLogins::PASSWORD_LOCKED], errorBag: 'updatePassword');

        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_the_demo_email_match_ignores_case(): void
    {
        $this->assertTrue(DemoLogins::isSharedEmail(strtoupper(config('demo.accounts.employee.email'))));
        $this->assertFalse(DemoLogins::isSharedEmail('someone.else@example.com'));
    }

    public function test_the_platform_admin_demo_login_can_still_change_its_password(): void
    {
        $admin = User::factory()->role(Role::PlatformAdmin)->create([
            'email' => config('demo.accounts.platform_admin.email'),
            'password' => 'old-password-123',
        ]);
        $this->actingAs($admin);

        $this->get('/account')->assertOk()->assertSee('Change your password');
        $this->from('/account')->put('/user/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertSessionHas('status', 'password-updated');

        $this->assertTrue(Hash::check('new-password-456', $admin->fresh()->password));
    }

    #[DataProvider('demoLogins')]
    public function test_a_password_reset_refuses_a_shared_demo_login(string $key, Role $role): void
    {
        $user = User::factory()->role($role)->create([
            'email' => config("demo.accounts.$key.email"),
            'password' => 'old-password-123',
        ]);

        try {
            app(ResetUserPassword::class)->reset($user, ['password' => 'new-password-456', 'password_confirmation' => 'new-password-456']);
            $this->fail('The reset should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame([DemoLogins::PASSWORD_LOCKED], $e->errors()['email']);
        }

        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_a_password_reset_still_works_for_other_staff(): void
    {
        $admin = User::factory()->role(Role::BidAdmin)->create(['password' => 'old-password-123']);

        app(ResetUserPassword::class)->reset($admin, ['password' => 'new-password-456', 'password_confirmation' => 'new-password-456']);

        $this->assertTrue(Hash::check('new-password-456', $admin->fresh()->password));
    }

    public function test_reviewers_have_no_password_to_change(): void
    {
        $judge = User::factory()->role(Role::Judge)->create(['password' => 'old-password-123']);
        $this->actingAs($judge);

        $this->get('/account')->assertOk()->assertDontSee('password', false);

        $this->put('/user/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('old-password-123', $judge->fresh()->password));
    }
}
