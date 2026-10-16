<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Support\AppUrl;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public const PUBLIC_FEEDBACK_PER_MINUTE = 3;

    public const PUBLIC_FEEDBACK_PER_DAY = 20;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Production is reached through CloudFront over HTTPS: links built
        // anywhere, artisan commands included, use APP_URL (see UseAppUrl).
        if ($this->app->isProduction()) {
            AppUrl::force();
        }

        // Who may change what. Judges pass none of these; BlockJudgeWrites
        // also stops them at the door, so both layers must agree.
        Gate::define('reset-demo', fn (User $user) => $user->hasRole(Role::BidAdmin, Role::PlatformAdmin));
        Gate::define('decide-merchant-eligibility', fn (User $user) => $user->hasRole(Role::BidAdmin, Role::PlatformAdmin));
        Gate::define('redeem', fn (User $user) => $user->hasRole(Role::Employee));
        Gate::define('manage-employer-program', fn (User $user) => $user->hasRole(Role::EmployerAdmin));
        Gate::define('manage-reviewers', fn (User $user) => $user->hasRole(Role::PlatformAdmin));
        Gate::define('manage-feedback', fn (User $user) => $user->hasRole(Role::PlatformAdmin));
        Gate::define('manage-changelog', fn (User $user) => $user->hasRole(Role::PlatformAdmin));
        Gate::define('manage-adjudication', fn (User $user) => $user->hasRole(Role::PlatformAdmin));
        // The one write some judges may make: blind votes on eval cases (ADR 0011).
        Gate::define('adjudicate', fn (User $user) => $user->canAdjudicate());

        // Feedback: the shared public reviewer is anyone with the README link,
        // so it gets a tighter limit per visitor IP. Named reviewers and staff
        // get a roomier one per person.
        RateLimiter::for('feedback', fn (Request $request) => $request->user()?->isPublicReviewer()
            ? [
                Limit::perMinute(self::PUBLIC_FEEDBACK_PER_MINUTE)->by('public-feedback-minute|'.$request->ip()),
                Limit::perDay(self::PUBLIC_FEEDBACK_PER_DAY)->by('public-feedback-day|'.$request->ip()),
            ]
            : Limit::perMinute(10)->by('feedback|'.($request->user()?->id ?: $request->ip())));

        // "What's new since your last visit" compares against previous_visit_at.
        Event::listen(function (Login $event) {
            $event->user->forceFill([
                'first_login_at' => $event->user->first_login_at ?? now(),
                'previous_visit_at' => $event->user->last_seen_at,
                'last_seen_at' => now(),
            ])->saveQuietly();
        });
    }
}
