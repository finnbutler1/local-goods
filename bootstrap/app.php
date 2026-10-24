<?php

use App\Http\Middleware\BlockJudgeWrites;
use App\Http\Middleware\EndReviewerSessions;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\KeepPersonalContactsToAdjudication;
use App\Http\Middleware\RequireCloudFrontOrigin;
use App\Http\Middleware\TrackLastSeen;
use App\Http\Middleware\TrustCloudFront;
use App\Http\Middleware\UseAppUrl;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Stripe's webhook: outside the web group, so no session, CSRF or user (ADR 0015).
        then: fn () => Route::middleware('api')->group(base_path('routes/stripe.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind CloudFront: production URLs use APP_URL, and the visitor's
        // real IP is believed only from CloudFront itself (ADR 0009).
        $middleware->prepend(UseAppUrl::class);
        // In production, only CloudFront may reach Beanstalk (kill switch: ORIGIN_VERIFY_ENFORCE).
        $middleware->prepend(RequireCloudFrontOrigin::class);
        $middleware->replace(TrustProxies::class, TrustCloudFront::class);
        $middleware->alias(['role' => EnsureUserHasRole::class]);
        $middleware->appendToGroup('web', [EndReviewerSessions::class, BlockJudgeWrites::class, KeepPersonalContactsToAdjudication::class, TrackLastSeen::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A pay code is never sent back to the page as old input (ADR 0026).
        $exceptions->dontFlash(['pay_code']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
