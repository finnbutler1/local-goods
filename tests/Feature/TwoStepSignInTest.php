<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\User;
use App\Providers\FortifyServiceProvider;
use App\Services\ReviewerLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Optional two-step sign-in (Fortify's two-factor feature): opt-in, for real
 * password accounts only. Never for the four shared demo logins or judges,
 * and judges' magic links never meet it.
 */
class TwoStepSignInTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'fake-example-password-1';

    private const RECOVERY_CODES = ['fake-recovery-code-one', 'fake-recovery-code-two'];

    private function staff(Role $role = Role::PlatformAdmin, array $attributes = []): User
    {
        return User::factory()->role($role)->create(['password' => self::PASSWORD] + $attributes);
    }

    /** A staff account with two-step sign-in already on; returns it and its secret. */
    private function staffWithTwoStep(): array
    {
        $secret = (new Google2FA)->generateSecretKey();
        $user = $this->staff();
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(self::RECOVERY_CODES)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$user, $secret];
    }

    private function codeFor(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    private function logIn(User $user): TestResponse
    {
        return $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
    }

    private function passwordConfirmed(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function signOut(): void
    {
        auth()->logout();
        $this->flushSession();
    }

    // Turning it on

    public function test_the_setup_page_asks_for_the_password_first(): void
    {
        $this->actingAs($this->staff());

        $this->get('/account')->assertOk()->assertSee('Two-step sign-in')->assertSee('Set up two-step sign-in');
        $this->get('/account/two-step-sign-in')->assertRedirect(route('password.confirm'));
        $this->post('/user/two-factor-authentication')->assertRedirect(route('password.confirm'));

        $this->assertNull(auth()->user()->fresh()->two_factor_secret);
    }

    public function test_turning_it_on_needs_a_confirmed_code_before_it_is_active(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->passwordConfirmed();

        $this->get('/account/two-step-sign-in')->assertOk()->assertSee('Two-step sign-in is off');
        $this->from('/account/two-step-sign-in')->post('/user/two-factor-authentication')->assertRedirect('/account/two-step-sign-in');

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at, 'Not on until a code is confirmed.');
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());

        // Stored encrypted, never as the key itself.
        $secret = decrypt($user->two_factor_secret);
        $raw = DB::table('users')->where('id', $user->id)->first();
        $this->assertStringNotContainsString($secret, $raw->two_factor_secret);
        foreach ($user->recoveryCodes() as $code) {
            $this->assertStringNotContainsString($code, $raw->two_factor_recovery_codes);
        }

        $page = $this->get('/account/two-step-sign-in')->assertOk()->assertSee('Finish setting up')
            ->assertSee('autocomplete="one-time-code"', false)->assertSee('aria-label="QR code for your authenticator app', false);
        $page->assertSee(trim(chunk_split($secret, 4, ' ')));

        // A wrong code is refused and leaves it off.
        $this->from('/account/two-step-sign-in')->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])
            ->assertSessionHasErrors('code', errorBag: 'confirmTwoFactorAuthentication');
        $this->assertNull($user->fresh()->two_factor_confirmed_at);

        $this->from('/account/two-step-sign-in')->post('/user/confirmed-two-factor-authentication', ['code' => $this->codeFor($secret)])
            ->assertRedirect('/account/two-step-sign-in')->assertSessionHas('status', 'two-factor-authentication-confirmed');
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_recovery_codes_are_shown_once(): void
    {
        [$user] = $this->staffWithTwoStep();
        $this->actingAs($user)->passwordConfirmed();

        $this->withSession(['status' => 'two-factor-authentication-confirmed'])
            ->get('/account/two-step-sign-in')->assertOk()->assertSee(self::RECOVERY_CODES[0]);
        session()->forget('status'); // a flash lasts one page

        $this->get('/account/two-step-sign-in')->assertOk()->assertSee('Two-step sign-in is on')
            ->assertDontSee(self::RECOVERY_CODES[0])->assertDontSee('Setup key');
    }

    public function test_an_unfinished_setup_changes_nothing_at_sign_in(): void
    {
        $user = $this->staff();
        $user->forceFill(['two_factor_secret' => encrypt((new Google2FA)->generateSecretKey()), 'two_factor_recovery_codes' => encrypt('[]')])->save();

        $this->logIn($user)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_key_and_recovery_codes_cannot_be_read_back(): void
    {
        [$user] = $this->staffWithTwoStep();
        $this->actingAs($user)->passwordConfirmed();

        $this->get('/user/two-factor-qr-code')->assertNotFound();
        $this->get('/user/two-factor-secret-key')->assertNotFound();
        $this->get('/user/two-factor-recovery-codes')->assertNotFound();
    }

    // Signing in with it on

    public function test_sign_in_asks_for_a_code_and_accepts_a_valid_one(): void
    {
        [$user, $secret] = $this->staffWithTwoStep();

        $this->logIn($user)->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->get('/two-factor-challenge')->assertOk()->assertSee('Enter your sign-in code')
            ->assertSee('autocomplete="one-time-code"', false)->assertSee('Use a recovery code instead');

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect()->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => $this->codeFor($secret)])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertFalse(session()->has('login.id'));
    }

    public function test_the_same_code_cannot_be_used_twice(): void
    {
        [$user, $secret] = $this->staffWithTwoStep();
        $code = $this->codeFor($secret);

        $this->logIn($user);
        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/dashboard');
        $this->signOut();

        $this->logIn($user);
        $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_each_recovery_code_works_once(): void
    {
        [$user] = $this->staffWithTwoStep();

        $this->logIn($user);
        $this->post('/two-factor-challenge', ['recovery_code' => self::RECOVERY_CODES[0]])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains(self::RECOVERY_CODES[0], $user->fresh()->recoveryCodes());
        $this->assertContains(self::RECOVERY_CODES[1], $user->fresh()->recoveryCodes());
        $this->signOut();

        $this->logIn($user);
        $this->post('/two-factor-challenge', ['recovery_code' => self::RECOVERY_CODES[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['recovery_code' => self::RECOVERY_CODES[1]])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_codes_are_rate_limited(): void
    {
        [$user, $secret] = $this->staffWithTwoStep();
        $this->logIn($user);

        foreach (range(1, FortifyServiceProvider::TWO_STEP_CODES_PER_MINUTE) as $try) {
            $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        }

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertTooManyRequests();
        $this->post('/two-factor-challenge', ['code' => $this->codeFor($secret)])->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_the_code_page_without_a_pending_sign_in_goes_back_to_login(): void
    {
        $this->get('/two-factor-challenge')->assertRedirect('/login');
        $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->get('/two-factor-challenge')->assertRedirect('/login');
    }

    // Turning it off and new recovery codes

    public function test_turning_it_off_and_new_codes_need_the_password(): void
    {
        [$user] = $this->staffWithTwoStep();
        $this->actingAs($user);

        $this->delete('/user/two-factor-authentication')->assertRedirect(route('password.confirm'));
        $this->post('/user/two-factor-recovery-codes')->assertRedirect(route('password.confirm'));
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertSame(self::RECOVERY_CODES, $user->fresh()->recoveryCodes());

        $this->passwordConfirmed();
        $this->from('/account/two-step-sign-in')->post('/user/two-factor-recovery-codes')->assertSessionHas('status', 'recovery-codes-generated');
        $this->assertNotSame(self::RECOVERY_CODES, $user->fresh()->recoveryCodes());

        $this->from('/account/two-step-sign-in')->delete('/user/two-factor-authentication')->assertSessionHas('status', 'two-factor-authentication-disabled');
        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        $this->signOut();
        $this->logIn($user)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    // Shared demo logins

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
    public function test_a_shared_demo_login_can_never_turn_it_on(string $key, Role $role): void
    {
        $user = $this->staff($role, ['email' => config("demo.accounts.$key.email")]);
        $this->actingAs($user);

        $this->get('/account')->assertOk()
            ->assertSee('This is a shared demo login, so two-step sign-in can\'t be turned on.', false)
            ->assertDontSee('Set up two-step sign-in')
            ->assertDontSee(route('account.two-step'), false);

        $this->passwordConfirmed();
        $this->get('/account/two-step-sign-in')->assertForbidden();
        $this->post('/user/two-factor-authentication')->assertForbidden();
        $this->post('/user/confirmed-two-factor-authentication', ['code' => '123456'])->assertForbidden();
        $this->post('/user/two-factor-recovery-codes')->assertForbidden();
        $this->delete('/user/two-factor-authentication')->assertForbidden();

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_the_demo_platform_admin_login_may_use_it(): void
    {
        $admin = $this->staff(Role::PlatformAdmin, ['email' => config('demo.accounts.platform_admin.email')]);

        $this->assertTrue($admin->mayUseTwoStepSignIn());
        $this->actingAs($admin)->get('/account')->assertOk()->assertSee('Set up two-step sign-in');
    }

    public function test_demo_seed_accounts_clears_it_on_shared_logins_and_never_touches_anyone_else(): void
    {
        foreach (['platform_admin', 'bid_admin', 'employer_admin', 'employee', 'merchant'] as $role) {
            config(["demo.accounts.$role.password" => self::PASSWORD]);
        }

        // The platform admin (Finn's own login) and another staff account with it on.
        [$admin] = $this->staffWithTwoStep();
        $admin->forceFill(['email' => config('demo.accounts.platform_admin.email')])->save();
        [$otherStaff] = $this->staffWithTwoStep();
        $before = fn (User $user) => (array) DB::table('users')->where('id', $user->id)
            ->first(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        $adminBefore = $before($admin);
        $otherBefore = $before($otherStaff);

        // A shared demo login that somehow has it (written straight to the row).
        $employer = $this->staff(Role::EmployerAdmin, ['email' => config('demo.accounts.employer_admin.email')]);
        $employer->forceFill(['two_factor_secret' => encrypt('FAKEEXAMPLESECRET'), 'two_factor_confirmed_at' => now()])->save();

        $this->artisan('demo:seed-accounts')->assertSuccessful();

        $this->assertSame($adminBefore, $before($admin));
        $this->assertSame($otherBefore, $before($otherStaff));
        $this->assertTrue($admin->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertNull($employer->fresh()->two_factor_secret);
        $this->assertNull($employer->fresh()->two_factor_confirmed_at);

        $this->artisan('demo:seed-if-empty')->assertSuccessful();
        $this->assertSame($adminBefore, $before($admin));
        $this->assertSame($otherBefore, $before($otherStaff));
    }

    // Judges and their magic links

    public function test_a_magic_link_still_signs_in_with_no_code_even_if_the_row_had_two_step_columns(): void
    {
        config(['reviewers.judging_ends_at' => '2026-11-30 23:59']);
        $this->travelTo('2026-10-20 12:00');
        $links = app(ReviewerLinks::class);
        [$judge, $link] = $links->invite('Casey Morgan', 'casey.judge@example.test', ReviewerType::ContestJudge);
        $plainJudgeLink = $links->invite('Robin Example', 'robin.judge@example.test', ReviewerType::Bid)[1];

        $this->post('/judge-access', ['link' => Str::after($plainJudgeLink, '#')])->assertRedirect('/judge');
        $this->get('/judge')->assertOk();
        $this->post('/logout');
        $this->flushSession();

        // Judges can't turn it on, but even a row with the columns set is never challenged.
        $judge->forceFill(['two_factor_secret' => encrypt('FAKEEXAMPLESECRET'), 'two_factor_confirmed_at' => now()])->save();
        $hashBefore = $judge->fresh()->invite_token_hash;

        $this->post('/judge-access', ['link' => Str::after($link, '#')])->assertRedirect('/judge');
        $this->assertAuthenticatedAs($judge);
        $this->assertFalse(session()->has('login.id'));
        $this->assertSame($links->sessionFingerprint($judge->fresh()), session(ReviewerLinks::SESSION_KEY));
        $this->get('/judge')->assertOk();
        $this->assertSame($hashBefore, $judge->fresh()->invite_token_hash);
    }

    public function test_judges_cannot_turn_it_on_or_see_the_controls(): void
    {
        $judge = User::factory()->role(Role::Judge)->create();
        $this->actingAs($judge);

        $this->get('/account')->assertOk()->assertDontSee('Two-step sign-in');
        $this->passwordConfirmed();
        $this->get('/account/two-step-sign-in')->assertForbidden();
        $this->post('/user/two-factor-authentication')->assertForbidden();
        $this->assertNull($judge->fresh()->two_factor_secret);
    }

    // Everyone else

    public function test_without_it_sign_in_is_exactly_as_before(): void
    {
        $user = $this->staff(Role::BidAdmin);

        $this->logIn($user)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertFalse(session()->has('login.id'));
    }

    public function test_the_two_step_columns_never_appear_when_a_user_is_serialized(): void
    {
        [$user] = $this->staffWithTwoStep();

        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());
    }
}
