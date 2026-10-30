<?php

namespace App\Services\DemoActivity;

use App\Support\StripeMode;
use Carbon\CarbonImmutable;

/**
 * Whether the demo activity generator may run at all right now (ADR 0032).
 * Checked at the start of every tick, before anything is read or written.
 */
final class ActivityGuard
{
    public const TIMEZONE = 'America/New_York';

    /** Null when it may run; otherwise why not, in plain English. */
    public static function refusal(?CarbonImmutable $now = null): ?string
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE);

        if (! config('demo_activity.enabled')) {
            return 'Off: DEMO_ACTIVITY_ENABLED is not true.';
        }

        if (! StripeMode::active() || ! StripeTestPayments::isTestSecret(config('services.stripe.secret'))) {
            return 'Stripe test mode is not active (it needs test keys), so nothing runs.';
        }

        if ($now->toDateString() > (string) config('demo_activity.ends_on')) {
            return 'Ended on '.config('demo_activity.ends_on').'.';
        }

        if ($now->dayOfWeekIso > 5) {
            return 'Weekend: nothing runs.';
        }

        $time = $now->format('H:i');
        if ($time < config('demo_activity.hours.from') || $time >= config('demo_activity.hours.until')) {
            return 'Outside '.config('demo_activity.hours.from').'–'.config('demo_activity.hours.until').' DC time.';
        }

        return null;
    }

    /** "On", or why it is not running, for the status page. */
    public static function state(): string
    {
        if (! config('demo_activity.enabled')) {
            return 'off';
        }

        return CarbonImmutable::now(self::TIMEZONE)->toDateString() > (string) config('demo_activity.ends_on') ? 'ended' : 'on';
    }
}
