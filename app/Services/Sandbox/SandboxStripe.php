<?php

namespace App\Services\Sandbox;

/**
 * Real Stripe Checkout for Buy credits inside a sandbox (ADR 0045 addendum),
 * on a SEPARATE Stripe test account, so nothing reaches the live site's
 * Stripe balance, reconciliation, solvency or sweeps.
 *
 * The only place that reads SANDBOX_STRIPE_SECRET and
 * SANDBOX_STRIPE_PUBLISHABLE, and only while a sandbox is active: the live
 * site never uses either. A sandbox never uses the live
 * STRIPE_* keys either (Sandbox::activate blanks them). Never returns,
 * prints or logs a key.
 */
final class SandboxStripe
{
    /** The test-card sentence wherever a sandbox tells the employer how to pay (Buy credits, checklist step 1). */
    public const TEST_CARD_HINT = 'Use card 4242 4242 4242 4242 with any future expiry date (e.g. 12/34), any 3-digit CVC, any ZIP code and any name. No real money.';

    /** The switch alone (no key read), for wording outside a sandbox such as /sandbox. */
    public static function switchedOn(): bool
    {
        return config('sandbox.stripe.enabled') === true;
    }

    /** On for this request: inside a sandbox, switched on, and the key is an sk_test_ key. */
    public static function available(): bool
    {
        return Sandbox::active() && self::switchedOn() && self::validKey(config('sandbox.stripe.secret'));
    }

    /** The sandbox Checkout client, or null when the feature is off for this request. */
    public static function client(): ?SandboxCheckoutClient
    {
        if (! self::available()) {
            return null;
        }

        return app()->bound(SandboxCheckoutClient::class)
            ? app(SandboxCheckoutClient::class)
            : new SandboxStripeClient((string) config('sandbox.stripe.secret'));
    }

    /**
     * The sandbox account's publishable key for Stripe's embedded card form,
     * or null: outside a sandbox, switched off, a bad secret key, or a
     * publishable key that is missing or not pk_test_ (then hosted Checkout).
     * Goes only into the sandbox employer page's data-stripe-key.
     */
    public static function publishableKey(): ?string
    {
        if (! self::available()) {
            return null;
        }
        $key = config('sandbox.stripe.publishable');

        return self::validPublishable($key) ? $key : null;
    }

    /** Stripe's embedded card form on our page (otherwise hosted Checkout, otherwise simulated). */
    public static function embeddedAvailable(): bool
    {
        return self::publishableKey() !== null;
    }

    /** Only publishable test keys. A live key, a secret key or nothing: hosted Checkout instead. */
    public static function validPublishable(mixed $key): bool
    {
        return is_string($key) && str_starts_with($key, 'pk_test_') && strlen($key) > strlen('pk_test_');
    }

    /** Only secret test keys. A live key, a restricted key, a publishable key or nothing: off. */
    public static function validKey(mixed $key): bool
    {
        return is_string($key) && str_starts_with($key, 'sk_test_') && strlen($key) > strlen('sk_test_');
    }

    /** What goes in Stripe metadata for this sandbox: a hash of its id, so the file name never leaves the server. */
    public static function tag(): string
    {
        return substr(hash('sha256', 'lg-sandbox:'.(string) app(Sandbox::class)->id()), 0, 32);
    }

    /**
     * For app:check-production-config (a deploy check, outside any web
     * request): the sandbox key must never be the live key, or sandbox
     * payments would land in the live Stripe balance. Compares, never prints.
     */
    public static function problem(): ?string
    {
        $sandbox = config('sandbox.stripe.secret');
        $live = config('services.stripe.secret');

        if (self::switchedOn() && is_string($sandbox) && $sandbox !== '' && is_string($live) && hash_equals($live, $sandbox)) {
            return 'SANDBOX_STRIPE_SECRET is the same as STRIPE_SECRET. Sandbox payments must use a separate Stripe test account: set a key from that account, or unset SANDBOX_STRIPE_ENABLED.';
        }

        return null;
    }

    /**
     * For app:check-production-config: the sandbox's publishable key must
     * never be the live STRIPE_KEY either. Compares, never prints.
     */
    public static function publishableProblem(): ?string
    {
        $sandbox = config('sandbox.stripe.publishable');
        $live = config('services.stripe.key');

        if (self::switchedOn() && is_string($sandbox) && $sandbox !== '' && is_string($live) && hash_equals($live, $sandbox)) {
            return 'SANDBOX_STRIPE_PUBLISHABLE is the same as STRIPE_KEY. The sandbox card form must use the separate Stripe test account: set that account\'s publishable key, or unset SANDBOX_STRIPE_PUBLISHABLE.';
        }

        return null;
    }
}
