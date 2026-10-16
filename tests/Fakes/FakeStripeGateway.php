<?php

namespace Tests\Fakes;

use App\Services\Payments\StripeGateway;
use App\Services\Payments\StripeGatewayException;

/**
 * Stripe for tests: no network. Records every call, hands out predictable
 * ids, remembers idempotency keys like Stripe does, and can be told to fail.
 */
class FakeStripeGateway implements StripeGateway
{
    /** @var list<array{0: string, 1: array}> */
    public array $calls = [];

    /** @var array<string, array> */
    public array $accounts = [];

    /** @var list<array> */
    public array $transfers = [];

    /** @var list<array> PaymentIntents Stripe "has", for reconciliation. */
    public array $paymentIntents = [];

    public array $balance = ['available' => [['amount' => 0, 'currency' => 'usd']], 'pending' => [['amount' => 0, 'currency' => 'usd']]];

    /** Next transfer fails with this (plain English), once per entry. */
    public array $transferFailures = [];

    /** @var array<string, array> */
    private array $byIdempotencyKey = [];

    private int $counter = 0;

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->remember($idempotencyKey, function () use ($params) {
            $this->calls[] = ['checkout', $params];
            $id = 'cs_test_'.$this->next();

            return ['id' => $id, 'url' => 'https://checkout.stripe.test/'.$id];
        });
    }

    public function createExpressAccount(array $params, string $idempotencyKey): array
    {
        return $this->remember($idempotencyKey, function () use ($params) {
            $this->calls[] = ['account', $params];
            $id = 'acct_test_'.$this->next();

            return $this->accounts[$id] = ['id' => $id, 'details_submitted' => false, 'payouts_enabled' => false, 'capabilities' => ['transfers' => 'inactive']];
        });
    }

    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): array
    {
        $this->calls[] = ['account_link', compact('accountId', 'refreshUrl', 'returnUrl')];

        return ['url' => 'https://connect.stripe.test/setup/'.$accountId];
    }

    public function retrieveAccount(string $accountId): array
    {
        $this->calls[] = ['retrieve_account', ['id' => $accountId]];

        return $this->accounts[$accountId] ?? throw new StripeGatewayException('No such account.');
    }

    /** Pretends the merchant finished Stripe's onboarding form. */
    public function completeOnboarding(string $accountId): array
    {
        return $this->accounts[$accountId] = ['id' => $accountId, 'details_submitted' => true, 'payouts_enabled' => true, 'capabilities' => ['transfers' => 'active']];
    }

    public function createTransfer(array $params, string $idempotencyKey): array
    {
        return $this->remember($idempotencyKey, function () use ($params) {
            $this->calls[] = ['transfer', $params];

            if ($this->transferFailures !== []) {
                [$message, $definite] = array_shift($this->transferFailures) + [1 => true];

                throw new StripeGatewayException($message, definite: $definite);
            }

            return $this->transfers[] = $params + ['id' => 'tr_test_'.$this->next(), 'object' => 'transfer', 'created' => time(), 'reversed' => false];
        });
    }

    public function retrieveBalance(): array
    {
        $this->calls[] = ['balance', []];

        return $this->balance;
    }

    public function listPaymentIntents(int $since, int $max = 300): array
    {
        return $this->paymentIntents;
    }

    public function listTransfers(int $since, int $max = 300): array
    {
        return $this->transfers;
    }

    /** @return list<array> the params of every call of this kind */
    public function callsOf(string $kind): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter($this->calls, fn ($c) => $c[0] === $kind)));
    }

    private function remember(string $key, callable $make): array
    {
        return $this->byIdempotencyKey[$key] ??= $make();
    }

    private function next(): string
    {
        return str_pad((string) ++$this->counter, 4, '0', STR_PAD_LEFT);
    }
}
