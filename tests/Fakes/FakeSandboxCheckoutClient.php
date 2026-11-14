<?php

namespace Tests\Fakes;

use App\Services\Payments\StripeGatewayException;
use App\Services\Sandbox\Sandbox;
use App\Services\Sandbox\SandboxCheckoutClient;
use PHPUnit\Framework\Assert;

/**
 * The sandbox's Stripe Checkout client in tests (ADR 0045 addendum). Keeps
 * the sessions it made; pay() plays the employer paying with the test card.
 * Every call asserts it is made inside a sandbox, like the real client.
 */
class FakeSandboxCheckoutClient implements SandboxCheckoutClient
{
    /** @var list<array{0: string, 1: mixed}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    public bool $down = false;

    private int $next = 1;

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        $this->record('create', $params);

        $id = 'cs_test_sandbox_fake_'.$this->next++;

        // Embedded (ui_mode set): a client secret and no url, as Stripe answers; hosted: a url and no client secret.
        $embedded = isset($params['ui_mode']);

        return $this->sessions[$id] = [
            'id' => $id,
            'object' => 'checkout.session',
            'ui_mode' => $embedded ? $params['ui_mode'] : 'hosted',
            'url' => $embedded ? null : 'https://checkout.stripe.com/c/pay/'.$id,
            'client_secret' => $embedded ? $id.'_secret_fake_not_a_real_secret' : null,
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => array_sum(array_map(fn ($l) => $l['price_data']['unit_amount'] * $l['quantity'], $params['line_items'])),
            'currency' => $params['line_items'][0]['price_data']['currency'],
            'metadata' => $params['metadata'],
            'payment_intent' => null,
            'params' => $params,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        $this->record('retrieve', $sessionId);

        return $this->sessions[$sessionId] ?? throw new StripeGatewayException('No such checkout.session (fake).', definite: true);
    }

    public function expireCheckoutSession(string $sessionId): array
    {
        $this->record('expire', $sessionId);
        $this->sessions[$sessionId]['status'] = 'expired';

        return $this->sessions[$sessionId];
    }

    /** The employer pays with 4242 4242 4242 4242. */
    public function pay(string $sessionId, array $overrides = []): void
    {
        $this->sessions[$sessionId] = array_replace($this->sessions[$sessionId], [
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_sandbox_fake_'.$sessionId,
        ], $overrides);
    }

    public function latestId(): string
    {
        return array_key_last($this->sessions);
    }

    public function count(string $kind): int
    {
        return count(array_filter($this->calls, fn ($c) => $c[0] === $kind));
    }

    private function record(string $kind, mixed $what): void
    {
        Assert::assertTrue(Sandbox::active(), "The sandbox Stripe client was used outside a sandbox ({$kind}).");
        if ($this->down) {
            throw new StripeGatewayException('Could not reach Stripe. Nothing was lost; it will be tried again.', definite: false);
        }
        $this->calls[] = [$kind, $what];
    }
}
