<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\ExceptionInterface as StripeExceptionInterface;
use Stripe\Exception\PermissionException;
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
        // Stripe's official switch for its informational notices. Not enough on
        // its own (see StripeNotices), but it keeps them out of the way.
        if (config('services.stripe.suppress_notices')) {
            putenv('STRIPE_SUPPRESS_NOTICES=true');
        }

        return $this->client ??= new StripeClient(['api_key' => (string) config('services.stripe.secret'), 'max_network_retries' => 1]);
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->call('checkout.create', fn () => $this->client()->checkout->sessions->create($params, ['idempotency_key' => $idempotencyKey]));
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        return $this->call('checkout.retrieve', fn () => $this->client()->checkout->sessions->retrieve($sessionId));
    }

    public function expireCheckoutSession(string $sessionId): array
    {
        return $this->call('checkout.expire', fn () => $this->client()->checkout->sessions->expire($sessionId));
    }

    public function createTestPayment(array $params, string $idempotencyKey): array
    {
        return $this->call('payment_intents.create', fn () => $this->client()->paymentIntents->create($params, ['idempotency_key' => $idempotencyKey]));
    }

    /**
     * Accounts v2 with the Express dashboard. Stripe refuses new v1 Express
     * accounts for this platform ("Create connected accounts with POST
     * /v2/core/accounts instead"), so accounts, links and status use v2;
     * transfers stay on v1 /transfers, which v2 recipient accounts accept.
     */
    public function createExpressAccount(array $params, string $idempotencyKey): array
    {
        return $this->call('v2.accounts.create', fn () => $this->client()->v2->core->accounts->create(
            $params + ['dashboard' => 'express', 'include' => self::ACCOUNT_INCLUDE],
            ['idempotency_key' => $idempotencyKey],
        ));
    }

    /**
     * The same v2 account, but with no Stripe dashboard, so Local Goods may
     * fill in Stripe's test details and accept the terms itself. Stripe
     * refuses that for Express ("tos_acceptance_on_behalf_not_allowed").
     */
    public function createDemoAccount(array $params, string $idempotencyKey): array
    {
        return $this->call('v2.accounts.create', fn () => $this->client()->v2->core->accounts->create(
            ['dashboard' => 'none', 'include' => self::ACCOUNT_INCLUDE] + $params,
            ['idempotency_key' => $idempotencyKey],
        ));
    }

    /** btok_us_verified is Stripe's test-mode token for a verified US bank account; it only works with test keys. */
    public function addTestBankAccount(string $accountId, string $idempotencyKey): array
    {
        return $this->call('external_accounts.create', fn () => $this->client()->accounts->createExternalAccount(
            $accountId, ['external_account' => 'btok_us_verified'], ['idempotency_key' => $idempotencyKey],
        ));
    }

    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): array
    {
        return $this->call('v2.account_links.create', fn () => $this->client()->v2->core->accountLinks->create([
            'account' => $accountId,
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => ['refresh_url' => $refreshUrl, 'return_url' => $returnUrl],
            ],
        ]));
    }

    public function retrieveAccount(string $accountId): array
    {
        return $this->call('v2.accounts.retrieve', fn () => $this->client()->v2->core->accounts->retrieve($accountId, ['include' => self::ACCOUNT_INCLUDE]));
    }

    /** v2 leaves these out unless asked; onboarding status needs both. */
    private const ACCOUNT_INCLUDE = ['configuration.recipient', 'requirements'];

    public function createTransfer(array $params, string $idempotencyKey): array
    {
        return $this->call('transfers.create', fn () => $this->client()->transfers->create($params, ['idempotency_key' => $idempotencyKey]));
    }

    public function reverseTransfer(string $transferId, array $params, string $idempotencyKey): array
    {
        return $this->call('transfers.reversals.create', fn () => $this->client()->transfers->createReversal($transferId, $params, ['idempotency_key' => $idempotencyKey]));
    }

    public function createRefund(array $params, string $idempotencyKey): array
    {
        return $this->call('refunds.create', fn () => $this->client()->refunds->create($params, ['idempotency_key' => $idempotencyKey]));
    }

    public function listRefunds(int $since, int $max = 300): array
    {
        return $this->call('refunds.list', fn () => $this->collect($this->client()->refunds->all(['created' => ['gte' => $since], 'limit' => 100]), $max), list: true);
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
            // The param is a field name, and reason() is Stripe's own text with anything key-like removed.
            Log::warning('Stripe call failed', ['call' => $what, 'type' => class_basename($e), 'code' => $e->getStripeCode(), 'param' => $e->getError()?->param, 'status' => $e->getHttpStatus(), 'reason' => self::reason($e)]);

            throw new StripeGatewayException(self::plainEnglish($e), definite: ($e->getHttpStatus() ?? 500) < 500, stripeCode: $e->getStripeCode());
        } catch (StripeExceptionInterface $e) {
            // Anything else the SDK throws (an unreadable reply, a bad argument).
            Log::warning('Stripe call failed', ['call' => $what, 'type' => class_basename($e)]);

            throw new StripeGatewayException('Stripe sent a reply Local Goods could not read. Nothing was changed; try again.', definite: false);
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
            default => 'Stripe refused the request ('.($e->getStripeCode() ?? class_basename($e))
                .($e->getError()?->param ? ', field '.$e->getError()->param : '').')'
                .(self::reason($e) !== null ? '. Stripe says: '.self::reason($e) : '.'),
        };
    }

    /**
     * Stripe's own explanation, when it is safe to show: never for key or
     * permission errors (their text can describe the key), and only the
     * first sentence plus any "- field: ..." lines, with anything that looks
     * like a key removed, so example code and long guidance are left out.
     */
    public static function reason(ApiErrorException $e): ?string
    {
        if ($e instanceof AuthenticationException || $e instanceof PermissionException) {
            return null;
        }

        $text = trim((string) ($e->getError()?->message ?? ''));
        if ($text === '') {
            return null;
        }

        $lines = preg_split('/\R/', $text);
        $kept = [trim($lines[0])];
        foreach ($lines as $line) {
            if (preg_match('/^\s*-\s+\w[\w.\[\]]*:\s/', $line) && count($kept) < 4) {
                $kept[] = trim($line);
            }
        }

        return mb_strimwidth(StripeNotices::redact(implode(' ', $kept)), 0, 300, '…');
    }
}
