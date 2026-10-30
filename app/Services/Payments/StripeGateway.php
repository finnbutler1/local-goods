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

    /**
     * Creates and confirms a PaymentIntent with a Stripe test card, for the
     * demo activity generator (ADR 0032) only. Test mode only.
     *
     * @return array<string, mixed> a PaymentIntent
     */
    public function createTestPayment(array $params, string $idempotencyKey): array;

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

    /**
     * Pulls part or all of a sent Transfer back from the merchant (a refund,
     * ADR 0028). Stripe answers at once; the transfer.reversed event lists
     * every reversal with its metadata, and amount_reversed adds up.
     *
     * @return array<string, mixed> a TransferReversal
     */
    public function reverseTransfer(string $transferId, array $params, string $idempotencyKey): array;

    /**
     * Refunds part of a card payment to the employer (ADR 0028). In test mode
     * Stripe answers succeeded at once, even while the payment's funds are
     * still pending; refund.created carries the same status.
     *
     * @return array<string, mixed> a Refund
     */
    public function createRefund(array $params, string $idempotencyKey): array;

    /** @return list<array<string, mixed>> Refunds created at or after $since (unix time) */
    public function listRefunds(int $since, int $max = 300): array;

    /** @return array<string, mixed> the platform Balance */
    public function retrieveBalance(): array;

    /** @return list<array<string, mixed>> PaymentIntents created at or after $since (unix time) */
    public function listPaymentIntents(int $since, int $max = 300): array;

    /** @return list<array<string, mixed>> Transfers created at or after $since (unix time) */
    public function listTransfers(int $since, int $max = 300): array;
}
