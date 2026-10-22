<?php

namespace App\Services\Payments;

/**
 * Wraps any StripeGateway so no Stripe notice or user warning raised during
 * a call can escape it (StripeNotices). Bound around the real SDK gateway in
 * AppServiceProvider, and around the fake in tests.
 */
class NoticeSafeStripeGateway implements StripeGateway
{
    public function __construct(private StripeGateway $inner) {}

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return StripeNotices::quietly('checkout.create', fn () => $this->inner->createCheckoutSession($params, $idempotencyKey));
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        return StripeNotices::quietly('checkout.retrieve', fn () => $this->inner->retrieveCheckoutSession($sessionId));
    }

    public function expireCheckoutSession(string $sessionId): array
    {
        return StripeNotices::quietly('checkout.expire', fn () => $this->inner->expireCheckoutSession($sessionId));
    }

    public function createExpressAccount(array $params, string $idempotencyKey): array
    {
        return StripeNotices::quietly('accounts.create', fn () => $this->inner->createExpressAccount($params, $idempotencyKey));
    }

    public function createDemoAccount(array $params, string $idempotencyKey): array
    {
        return StripeNotices::quietly('accounts.create', fn () => $this->inner->createDemoAccount($params, $idempotencyKey));
    }

    public function addTestBankAccount(string $accountId, string $idempotencyKey): array
    {
        return StripeNotices::quietly('external_accounts.create', fn () => $this->inner->addTestBankAccount($accountId, $idempotencyKey));
    }

    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): array
    {
        return StripeNotices::quietly('account_links.create', fn () => $this->inner->createAccountLink($accountId, $refreshUrl, $returnUrl));
    }

    public function retrieveAccount(string $accountId): array
    {
        return StripeNotices::quietly('accounts.retrieve', fn () => $this->inner->retrieveAccount($accountId));
    }

    public function createTransfer(array $params, string $idempotencyKey): array
    {
        return StripeNotices::quietly('transfers.create', fn () => $this->inner->createTransfer($params, $idempotencyKey));
    }

    public function retrieveBalance(): array
    {
        return StripeNotices::quietly('balance.retrieve', fn () => $this->inner->retrieveBalance());
    }

    public function listPaymentIntents(int $since, int $max = 300): array
    {
        return StripeNotices::quietly('payment_intents.list', fn () => $this->inner->listPaymentIntents($since, $max));
    }

    public function listTransfers(int $since, int $max = 300): array
    {
        return StripeNotices::quietly('transfers.list', fn () => $this->inner->listTransfers($since, $max));
    }
}
