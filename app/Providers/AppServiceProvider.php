<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Services\Payments\NoticeSafeStripeGateway;
use App\Services\Geo\DcMarGeocoder;
use App\Services\Geo\Geocoder;
use App\Services\Payments\StripeGateway;
use App\Services\Payments\StripeSdkGateway;
use App\Support\AppUrl;
use App\Support\ProductionConfig;
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

    public const REDEEM_PER_MINUTE = 10;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Only ever reached in Stripe test mode; tests bind a fake (ADR 0015).
        // Wrapped so a Stripe notice can never crash a request (StripeNotices).
        $this->app->singleton(StripeGateway::class, fn ($app) => new NoticeSafeStripeGateway($app->make(StripeSdkGateway::class)));
        // DC's Master Address Repository (ADR 0031); tests fake its HTTP answers.
        $this->app->bind(Geocoder::class, DcMarGeocoder::class);
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

        // Production never shows debug pages, even if APP_DEBUG slipped through the deploy check.
        ProductionConfig::forceDebugOff();

        // Who may change what. Judges pass none of these; BlockJudgeWrites
        // also stops them at the door, so both layers must agree.
        Gate::define('reset-demo', fn (User $user) => $user->hasRole(Role::BidAdmin, Role::PlatformAdmin));
        // Admins only: one real AI call per press (ADR 0016). Judges never see or use it.
        Gate::define('run-collusion-example', fn (User $user) => $user->hasRole(Role::BidAdmin, Role::PlatformAdmin));
        Gate::define('decide-merchant-eligibility', fn (User $user) => $user->hasRole(Role::BidAdmin, Role::PlatformAdmin));
        Gate::define('redeem', fn (User $user) => $user->hasRole(Role::Employee));
        // The merchant's own login confirms pay codes at the counter (ADR 0026).
        Gate::define('take-payments', fn (User $user) => $user->hasRole(Role::Merchant));
        Gate::define('manage-employer-program', fn (User $user) => $user->hasRole(Role::EmployerAdmin));
        // Asking for a correction moves no money (ADR 0028). Judges and personal contacts never ask.
        Gate::define('request-corrections', fn (User $user) => $user->hasRole(Role::Employee, Role::Merchant, Role::EmployerAdmin));
        // Deciding one moves money, so only the platform operator decides; the BID admin and judges view.
        Gate::define('decide-corrections', fn (User $user) => $user->hasRole(Role::PlatformAdmin));
        // The merchant fee setting (ADR 0029) changes money on every new sale: the platform operator only.
        Gate::define('change-merchant-fee', fn (User $user) => $user->hasRole(Role::PlatformAdmin));
        // The BID partnership (ADR 0031): the platform operator only; the BID admin and judges read it.
        Gate::define('change-partnership', fn (User $user) => $user->hasRole(Role::PlatformAdmin));
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

        // Each redemption is one Redemption Fraud Detection call to Claude.
        RateLimiter::for('redeem', fn (Request $request) => Limit::perMinute(self::REDEEM_PER_MINUTE)->by('redeem|'.($request->user()?->id ?: $request->ip())));

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
