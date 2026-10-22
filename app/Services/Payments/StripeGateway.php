<?php

namespace App\Services\Payments;

/**
 * Everything Local Goods asks Stripe to do, in one small interface so tests
 * can swap in Tests\Fakes\FakeStripeGateway and never touch the network.
 * Arrays in, arrays out (plain Stripe JSON), so callers don't depend on SDK
 * objects. Any failure throws StripeGatewayException.
 */
interface StripeGateway
{
    /** @return array{id: string, client_secret?: string, url?: string} a Checkout Session (client_secret for ui_mode embedded_page) */
    public function createCheckoutSession(array $params, string $idempotencyKey): array;

    /** @return array{id: string, status: string} a Checkout Session; status is open, complete or expired */
    public function retrieveCheckoutSession(string $sessionId): array;

    /** Expires an open Checkout Session, so it can never take a payment. Stripe refuses one that isn't open. */
    public function expireCheckoutSession(string $sessionId): array;

    /** @return array<string, mixed> an Account */
    public function createExpressAccount(array $params, string $idempotencyKey): array;

    /**
     * A v2 account with no Stripe dashboard, details filled in by Local Goods.
     * Only for fictional demo merchants with no login (DemoMerchantStripeSetup).
     *
     * @return array<string, mixed> an Account
     */
    public function createDemoAccount(array $params, string $idempotencyKey): array;

    /** Gives a test-mode account Stripe's verified test bank account, so payouts are enabled. @return array<string, mixed> a bank account */
    public function addTestBankAccount(string $accountId, string $idempotencyKey): array;

    /** @return array{url: string} */
    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): array;

    /** @return array<string, mixed> an Account */
    public function retrieveAccount(string $accountId): array;

    /** @return array<string, mixed> a Transfer */
    public function createTransfer(array $params, string $idempotencyKey): array;

    /** @return array<string, mixed> the platform Balance */
    public function retrieveBalance(): array;

    /** @return list<array<string, mixed>> PaymentIntents created at or after $since (unix time) */
    public function listPaymentIntents(int $since, int $max = 300): array;

    /** @return list<array<string, mixed>> Transfers created at or after $since (unix time) */
    public function listTransfers(int $since, int $max = 300): array;
}
