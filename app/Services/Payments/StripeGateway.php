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
    /** @return array{id: string, url: string} */
    public function createCheckoutSession(array $params, string $idempotencyKey): array;

    /** @return array<string, mixed> an Account */
    public function createExpressAccount(array $params, string $idempotencyKey): array;

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
