<?php

namespace App\Services\Sandbox;

/**
 * The only three Stripe calls a sandbox may make (ADR 0045 addendum): open,
 * read back and expire a hosted Checkout Session on the SEPARATE sandbox
 * Stripe test account. Deliberately not a StripeGateway, so the sandbox key
 * can never reach payouts, transfers, refunds, balances or sweeps. Tests use
 * Tests\Fakes\FakeSandboxCheckoutClient. Failures throw StripeGatewayException.
 */
interface SandboxCheckoutClient
{
    /** @return array<string, mixed> a Checkout Session (id, url, ...) */
    public function createCheckoutSession(array $params, string $idempotencyKey): array;

    /** @return array<string, mixed> a Checkout Session (status, payment_status, amount_total, currency, metadata, payment_intent) */
    public function retrieveCheckoutSession(string $sessionId): array;

    /** @return array<string, mixed> */
    public function expireCheckoutSession(string $sessionId): array;
}
