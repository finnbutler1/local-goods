<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * The settings production must have (see .ebextensions/laravel.config and
 * "Security" in TRANSPARENCY.md). The deploy hook runs
 * app:check-production-config, which stops a deploy or configuration change
 * that breaks one of them before PHP restarts. Says which setting is wrong,
 * never what it contains.
 */
class ProductionConfig
{
    /** @return list<string> What is wrong, in plain English; empty when all is well. */
    public static function problems(): array
    {
        $problems = [];

        if (config('app.env') !== 'production') {
            $problems[] = 'APP_ENV must be "production".';
        }
        if (config('app.debug') === true) {
            $problems[] = 'APP_DEBUG must be "false": debug pages show code, settings and data to anyone.';
        }
        if (config('session.secure') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE must be "true", so the login cookie is only sent over HTTPS.';
        }
        if (config('services.cloudfront.enforce') && (string) config('services.cloudfront.origin_secret') === '') {
            $problems[] = 'CLOUDFRONT_ORIGIN_SECRET is empty, so every visitor would be refused. Set it to the X-Origin-Verify value CloudFront sends, or set ORIGIN_VERIFY_ENFORCE to "false".';
        }

        return $problems;
    }

    /**
     * A last line of defence if the hook was skipped: production never shows
     * debug pages. (Throwing here would itself show one.)
     */
    public static function forceDebugOff(): void
    {
        if (config('app.env') === 'production' && config('app.debug') === true) {
            config(['app.debug' => false]);
            Log::critical('APP_DEBUG was true in production; it has been turned off for this request. Set APP_DEBUG to "false" in Beanstalk.');
        }
    }
}
