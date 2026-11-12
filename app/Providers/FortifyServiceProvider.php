<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * Login, logout, password reset and optional two-step sign-in come from Laravel Fortify. It has no
 * pages of its own: the views below use our design system. There is no
 * registration: accounts are created by an admin.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public const AUTH_FORMS_PER_MINUTE = 10;

    public const TWO_STEP_CODES_PER_MINUTE = 5;

    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        // Asked for before the two-step sign-in page and its buttons (password.confirm).
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        // The code page after the password, for accounts that turned two-step sign-in on.
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        // Normal email + password check. Reviewers (judges) never use a
        // password: they sign in only with their magic link (ReviewerLinks).
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', Str::lower($request->input(Fortify::username())))->first();

            return $user && ! $user->isReadOnly() && ! $user->isRevoked() && Hash::check($request->input('password'), $user->password) ? $user : null;
        });

        // Five tries a minute for each email + IP address pair.
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // The two-step sign-in code: five tries a minute for each pending sign-in
        // (the account that just gave the right password), or per IP address
        // without one. The auth-forms limit below applies on top.
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(self::TWO_STEP_CODES_PER_MINUTE)
            ->by('two-factor|'.($request->session()->get('login.id') ?: $request->ip())));

        // Every Fortify form post (password change and confirm, reset, logout,
        // and login on top of the limit above): ten a minute per person, or
        // per IP address when signed out, or on the shared public judge account
        // (one count per person there would lock out everyone using the link).
        // Pages (GET) are not limited.
        RateLimiter::for('auth-forms', fn (Request $request) => $request->isMethodSafe()
            ? Limit::none()
            : Limit::perMinute(self::AUTH_FORMS_PER_MINUTE)->by('auth-forms|'.($request->user()?->isPublicReviewer() ? $request->ip() : ($request->user()?->id ?: $request->ip()))));
    }
}
