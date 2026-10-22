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

    /** Next Checkout Session fails with this (plain English), once. */
    public ?string $checkoutFailure = null;

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->remember($idempotencyKey, function () use ($params) {
            $this->calls[] = ['checkout', $params];

            // Like the real API (2026-09-30): Checkout refuses payment_method_types.
            if (array_key_exists('payment_method_types', $params)) {
                throw new StripeGatewayException('Stripe refused the request (InvalidRequestException, field payment_method_types).');
            }

            if ($this->checkoutFailure !== null) {
                [$message, $this->checkoutFailure] = [$this->checkoutFailure, null];

                throw new StripeGatewayException($message);
            }
            $id = 'cs_test_'.$this->next();
            $this->sessions[$id] = ['id' => $id, 'object' => 'checkout.session', 'status' => 'open', 'ui_mode' => $params['ui_mode'] ?? 'hosted_page'];

            return ($params['ui_mode'] ?? null) === 'embedded_page'
                ? $this->sessions[$id] + ['client_secret' => $id.'_secret_fake']
                : $this->sessions[$id] + ['url' => 'https://checkout.stripe.test/'.$id];
        });
    }

    /** @var array<string, array> Checkout Sessions made, by id, with their status. */
    public array $sessions = [];

    /** Next retrieve or expire fails with this (plain English), once. */
    public ?string $sessionFailure = null;

    /** The employer paid in this session (as when Stripe completes it). */
    public function completeSession(string $id): void
    {
        $this->sessions[$id]['status'] = 'complete';
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        $this->calls[] = ['checkout.retrieve', ['id' => $sessionId]];
        $this->failSessionCallIfTold();

        return $this->sessions[$sessionId] ?? throw new StripeGatewayException('Stripe refused the request (InvalidRequestException, No such checkout.session).');
    }

    public function expireCheckoutSession(string $sessionId): array
    {
        $this->calls[] = ['checkout.expire', ['id' => $sessionId]];
        $this->failSessionCallIfTold();

        // Like the real API: only an open session can be expired.
        if (($this->sessions[$sessionId]['status'] ?? null) !== 'open') {
            throw new StripeGatewayException('Stripe refused the request (InvalidRequestException, session is not open).');
        }

        $this->sessions[$sessionId]['status'] = 'expired';

        return $this->sessions[$sessionId];
    }

    private function failSessionCallIfTold(): void
    {
        if ($this->sessionFailure !== null) {
            [$message, $this->sessionFailure] = [$this->sessionFailure, null];

            throw new StripeGatewayException($message);
        }
    }

    /**
     * If set, account creation raises this as a PHP user warning, exactly as
     * stripe-php 22 does for a "Stripe-Notice" response header.
     */
    public ?string $accountNotice = null;

    public function createExpressAccount(array $params, string $idempotencyKey): array
    {
        return $this->remember($idempotencyKey, function () use ($params) {
            $this->calls[] = ['account', $params];

            if ($this->accountNotice !== null) {
                trigger_error($this->accountNotice, E_USER_WARNING);
            }
            $id = 'acct_test_'.$this->next();

            return $this->accounts[$id] = self::v2Account($id, 'restricted', waitingOnMerchant: true);
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
        return $this->accounts[$accountId] = self::v2Account($accountId, 'active', waitingOnMerchant: false);
    }

    /** An Accounts v2 account as Stripe returns it with configuration.recipient and requirements included. */
    public static function v2Account(string $id, string $transfers, bool $waitingOnMerchant): array
    {
        return [
            'id' => $id,
            'object' => 'v2.core.account',
            'dashboard' => 'express',
            'configuration' => ['recipient' => ['applied' => true, 'capabilities' => ['stripe_balance' => [
                'stripe_transfers' => ['status' => $transfers, 'status_details' => []],
                'payouts' => ['status' => $transfers, 'status_details' => []],
            ]]]],
            'requirements' => ['entries' => $waitingOnMerchant
                ? [['awaiting_action_from' => 'user', 'description' => 'business_details', 'errors' => []]]
                : []],
        ];
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

    /** @var list<string> every idempotency key used, in order */
    public array $idempotencyKeys = [];

    private function remember(string $key, callable $make): array
    {
        $this->idempotencyKeys[] = $key;

        return $this->byIdempotencyKey[$key] ??= $make();
    }

    private function next(): string
    {
        return str_pad((string) ++$this->counter, 4, '0', STR_PAD_LEFT);
    }
}
