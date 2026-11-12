<?php

namespace Tests\Feature;

use App\Console\Commands\DisableTwoStepSignIn;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * auth:disable-two-step: the emergency way back in for one staff account that
 * lost its phone and recovery codes. Dry run unless --apply; never a demo
 * login or a judge; logs without the key, codes or email.
 */
class DisableTwoStepCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'fake-example-password-1';

    private function withTwoStep(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => encrypt((new Google2FA)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(['fake-recovery-code-one'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    private function staff(Role $role = Role::PlatformAdmin, array $attributes = []): User
    {
        return $this->withTwoStep(User::factory()->role($role)->create(['password' => self::PASSWORD] + $attributes));
    }

    /** @return array<string, mixed> */
    private function row(User $user): array
    {
        return (array) DB::table('users')->where('id', $user->id)->first();
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $user = $this->staff();
        $before = $this->row($user);

        $this->artisan('auth:disable-two-step', ['email' => $user->email])
            ->expectsOutputToContain('Would turn off two-step sign-in for account #'.$user->id)
            ->expectsOutputToContain('nothing was changed')
            ->assertSuccessful();

        $this->assertSame($before, $this->row($user));
    }

    public function test_apply_turns_it_off_and_the_password_works_alone_again(): void
    {
        Log::spy();
        $user = $this->staff(attributes: ['email' => 'staff.person@example.test']);
        $other = $this->staff(Role::BidAdmin);
        $before = $this->row($user);
        $otherBefore = $this->row($other);

        $this->artisan('auth:disable-two-step', ['email' => 'staff.person@EXAMPLE.test', '--apply' => true])
            ->expectsOutputToContain('Turned off two-step sign-in for account #'.$user->id)
            ->assertSuccessful();

        $after = $this->row($user);
        $this->assertNull($after['two_factor_secret']);
        $this->assertNull($after['two_factor_recovery_codes']);
        $this->assertNull($after['two_factor_confirmed_at']);
        $unchanged = fn (array $row) => array_diff_key($row, array_flip(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'updated_at']));
        $this->assertSame($unchanged($before), $unchanged($after), 'Only the two-step columns change.');
        $this->assertSame($otherBefore, $this->row($other));

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($user, $before) {
            $logged = $message.json_encode($context);

            return $message === DisableTwoStepSignIn::LOG_MESSAGE
                && $context === ['user_id' => $user->id, 'role' => 'platform_admin']
                && ! str_contains(strtolower($logged), 'staff.person')
                && ! str_contains($logged, $before['two_factor_secret'])
                && ! str_contains($logged, 'fake-recovery-code-one');
        });

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unfinished_setup_can_be_cleared_too(): void
    {
        $user = $this->staff();
        $user->forceFill(['two_factor_confirmed_at' => null])->save();

        $this->artisan('auth:disable-two-step', ['email' => $user->email, '--apply' => true])->assertSuccessful();
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_it_refuses_an_unknown_email(): void
    {
        Log::spy();
        $user = $this->staff();
        $before = $this->row($user);

        $this->artisan('auth:disable-two-step', ['email' => 'nobody@example.test', '--apply' => true])
            ->expectsOutputToContain('no account has that email')
            ->assertFailed();

        $this->assertSame($before, $this->row($user));
        Log::shouldNotHaveReceived('warning');
    }

    public static function demoLogins(): array
    {
        return [['bid_admin', Role::BidAdmin], ['employer_admin', Role::EmployerAdmin], ['employee', Role::Employee], ['merchant', Role::Merchant]];
    }

    #[DataProvider('demoLogins')]
    public function test_it_refuses_a_shared_demo_login(string $key, Role $role): void
    {
        $user = $this->staff($role, ['email' => config("demo.accounts.$key.email")]);
        $before = $this->row($user);

        $this->artisan('auth:disable-two-step', ['email' => $user->email, '--apply' => true])
            ->expectsOutputToContain('Refused')
            ->assertFailed();

        $this->assertSame($before, $this->row($user));
    }

    public function test_it_refuses_a_judge(): void
    {
        $judge = $this->withTwoStep(User::factory()->role(Role::Judge)->create());
        $before = $this->row($judge);

        $this->artisan('auth:disable-two-step', ['email' => $judge->email, '--apply' => true])->assertFailed();
        $this->assertSame($before, $this->row($judge));
    }

    public function test_it_refuses_an_account_without_two_step_sign_in(): void
    {
        $user = User::factory()->role(Role::PlatformAdmin)->create();
        $before = $this->row($user);

        $this->artisan('auth:disable-two-step', ['email' => $user->email, '--apply' => true])
            ->expectsOutputToContain('not on for that account')
            ->assertFailed();
        $this->assertSame($before, $this->row($user));
    }
}
