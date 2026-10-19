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
 * Login, logout and password reset come from Laravel Fortify. It has no
 * pages of its own: the views below use our design system. There is no
 * registration: accounts are created by an admin.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        // Nothing asks for it yet, but Fortify serves /user/confirm-password, which was a 500 without a view.
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
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
    }
}
