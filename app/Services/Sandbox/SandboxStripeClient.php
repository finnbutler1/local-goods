<?php

namespace App\Services\Sandbox;

use App\Services\Payments\StripeNotices;
use App\Services\Payments\StripeSdkGateway;

/**
 * The real SandboxCheckoutClient: the official SDK, built with the sandbox
 * Stripe account's key (never the live STRIPE_SECRET), and only the three
 * Checkout calls. Every call goes through StripeNotices::quietlyInSandbox,
 * which refuses outside a sandbox. Built only by SandboxStripe::client().
 */
final class SandboxStripeClient implements SandboxCheckoutClient
{
    private StripeSdkGateway $sdk;

    public function __construct(private string $secret)
    {
        $this->sdk = new StripeSdkGateway($secret);
    }

    /** For tests: whether this client was built with this key. Compares, never returns the key. */
    public function usesKey(string $key): bool
    {
        return hash_equals($this->secret, $key);
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return StripeNotices::quietlyInSandbox('sandbox.checkout.create', fn () => $this->sdk->createCheckoutSession($params, $idempotencyKey));
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        return StripeNotices::quietlyInSandbox('sandbox.checkout.retrieve', fn () => $this->sdk->retrieveCheckoutSession($sessionId));
    }

    public function expireCheckoutSession(string $sessionId): array
    {
        return StripeNotices::quietlyInSandbox('sandbox.checkout.expire', fn () => $this->sdk->expireCheckoutSession($sessionId));
    }
}
