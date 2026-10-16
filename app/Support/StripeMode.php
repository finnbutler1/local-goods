<?php

namespace App\Support;

/**
 * Which money mode this server is in. Stripe test mode is on only when all
 * three keys are set and they are test keys; anything else (CI, tests, a
 * server missing a key, or live keys) falls back to simulated mode, where
 * nothing is sent to Stripe and every record says "simulated" (ADR 0015).
 * Never returns or prints a key.
 */
class StripeMode
{
    public const STRIPE = 'stripe';

    public const SIMULATED = 'simulated';

    public static function active(): bool
    {
        return self::problem() === null;
    }

    public static function current(): string
    {
        return self::active() ? self::STRIPE : self::SIMULATED;
    }

    /**
     * Why Stripe test mode is off, in plain English, or null when it is on.
     * Says which setting is wrong, never what it contains.
     */
    public static function problem(): ?string
    {
        $key = (string) config('services.stripe.key');
        $secret = (string) config('services.stripe.secret');
        $webhook = (string) config('services.stripe.webhook_secret');

        if ($key === '' && $secret === '' && $webhook === '') {
            return 'No Stripe keys are set, so payments are simulated.';
        }

        $missing = array_keys(array_filter(['STRIPE_KEY' => $key, 'STRIPE_SECRET' => $secret, 'STRIPE_WEBHOOK_SECRET' => $webhook], fn ($v) => $v === ''));

        if ($missing !== []) {
            return 'Stripe keys are incomplete ('.implode(', ', $missing).' not set), so payments are simulated.';
        }

        if (! str_starts_with($key, 'pk_test_') || ! (str_starts_with($secret, 'sk_test_') || str_starts_with($secret, 'rk_test_'))) {
            return 'The Stripe keys are not test-mode keys. Local Goods only runs in Stripe test mode, so they are ignored and payments are simulated.';
        }

        return null;
    }

    /** The webhook signing secrets to try, in order. */
    public static function webhookSecrets(): array
    {
        return array_values(array_filter([
            (string) config('services.stripe.webhook_secret'),
            (string) config('services.stripe.connect_webhook_secret'),
        ], fn ($v) => $v !== ''));
    }

    /** Short label for badges. */
    public static function label(?string $mode = null): string
    {
        return ($mode ?? self::current()) === self::STRIPE ? 'Stripe test mode' : 'Simulated';
    }
}
