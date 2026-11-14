<?php

namespace App\Services\Sandbox;

/**
 * Real Stripe Checkout for Buy credits inside a sandbox (ADR 0045 addendum),
 * on a SEPARATE Stripe test account, so nothing reaches the live site's
 * Stripe balance, reconciliation, solvency or sweeps.
 *
 * The only place that reads SANDBOX_STRIPE_SECRET, and only while a sandbox
 * is active: the live site never uses it. A sandbox never uses the live
 * STRIPE_* keys either (Sandbox::activate blanks them). Never returns,
 * prints or logs a key.
 */
final class SandboxStripe
{
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
}
