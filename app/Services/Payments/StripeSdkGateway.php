<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\StripeObject;

/**
 * The real Stripe, through the official stripe/stripe-php SDK. Only built
 * when StripeMode::active(), so the secret is always a test key. Errors are
 * logged as type, Stripe code and HTTP status only: Stripe's own messages
 * can repeat part of a key.
 */
class StripeSdkGateway implements StripeGateway
{
    private ?StripeClient $client = null;

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient(['api_key' => (string) config('services.stripe.secret'), 'max_network_retries' => 1]);
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->call('checkout.create', fn () => $this->client()->checkout->sessions->create($params, ['idempotency_key' => $idempotencyKey]));
    }

    public function createExpressAccount(array $params, string $idempotencyKey): array
    {
        return $this->call('accounts.create', fn () => $this->client()->accounts->create($params + ['type' => 'express'], ['idempotency_key' => $idempotencyKey]));
    }

    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): array
    {
        return $this->call('account_links.create', fn () => $this->client()->accountLinks->create([
            'account' => $accountId, 'refresh_url' => $refreshUrl, 'return_url' => $returnUrl, 'type' => 'account_onboarding',
        ]));
    }

    public function retrieveAccount(string $accountId): array
    {
        return $this->call('accounts.retrieve', fn () => $this->client()->accounts->retrieve($accountId));
    }

    public function createTransfer(array $params, string $idempotencyKey): array
    {
        return $this->call('transfers.create', fn () => $this->client()->transfers->create($params, ['idempotency_key' => $idempotencyKey]));
    }

    public function retrieveBalance(): array
    {
        return $this->call('balance.retrieve', fn () => $this->client()->balance->retrieve());
    }

    public function listPaymentIntents(int $since, int $max = 300): array
    {
        return $this->call('payment_intents.list', fn () => $this->collect($this->client()->paymentIntents->all(['created' => ['gte' => $since], 'limit' => 100]), $max), list: true);
    }

    public function listTransfers(int $since, int $max = 300): array
    {
        return $this->call('transfers.list', fn () => $this->collect($this->client()->transfers->all(['created' => ['gte' => $since], 'limit' => 100]), $max), list: true);
    }

    /** @return list<StripeObject> */
    private function collect($page, int $max): array
    {
        $items = [];
        foreach ($page->autoPagingIterator() as $item) {
            $items[] = $item;
            if (count($items) >= $max) {
                break;
            }
        }

        return $items;
    }

    private function call(string $what, callable $request, bool $list = false): array
    {
        try {
            $result = $request();
        } catch (ApiConnectionException $e) {
            Log::warning('Stripe call failed', ['call' => $what, 'type' => 'connection']);

            throw new StripeGatewayException('Could not reach Stripe. Nothing was lost; it will be tried again.', definite: false);
        } catch (ApiErrorException $e) {
            Log::warning('Stripe call failed', ['call' => $what, 'type' => class_basename($e), 'code' => $e->getStripeCode(), 'status' => $e->getHttpStatus()]);

            throw new StripeGatewayException(self::plainEnglish($e), definite: ($e->getHttpStatus() ?? 500) < 500, stripeCode: $e->getStripeCode());
        }

        return $list
            ? array_map(fn (StripeObject $o) => $o->toArray(), $result)
            : $result->toArray();
    }

    private static function plainEnglish(ApiErrorException $e): string
    {
        return match ($e->getStripeCode()) {
            'balance_insufficient' => 'Stripe says the platform balance is too low for this transfer yet.',
            'account_invalid', 'insufficient_capabilities_for_transfer' => 'Stripe says this merchant can\'t receive transfers yet (onboarding not finished).',
            default => 'Stripe refused the request ('.($e->getStripeCode() ?? class_basename($e)).').',
        };
    }
}
