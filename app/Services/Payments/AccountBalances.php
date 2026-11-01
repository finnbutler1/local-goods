<?php

namespace App\Services\Payments;

use App\Models\OperatingAccount;
use App\Support\StripeMode;
use Illuminate\Support\Facades\Cache;

/**
 * The two real Stripe test-mode balances, side by side (ADR 0034): the
 * customer funds account (the platform balance) and Local Goods Operating.
 * For Where the money is. Read-only, cached for a minute so the page doesn't
 * wait on Stripe every time (errors are not cached), and never throws: if Stripe can't be reached
 * the page says so. Nothing is read with Stripe off.
 */
class AccountBalances
{
    public const CACHE_KEY = 'money.account-balances';

    public const CACHE_SECONDS = 60;

    public function __construct(private StripeGateway $stripe) {}

    /** @return array{stripe: bool, customer_cents: ?int, operating_cents: ?int, operating_set_up: bool, error: ?string} */
    public function read(): array
    {
        $account = OperatingAccount::current()?->stripe_account_id;
        $none = ['stripe' => StripeMode::active(), 'customer_cents' => null, 'operating_cents' => null, 'operating_set_up' => $account !== null, 'error' => null];

        if (! StripeMode::active()) {
            return $none;
        }

        $key = self::CACHE_KEY.':'.($account ?? 'none');
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        try {
            $out = $none;
            $out['customer_cents'] = self::total($this->stripe->retrieveBalance());
            if ($account !== null) {
                $out['operating_cents'] = self::total($this->stripe->retrieveAccountBalance($account));
            }
        } catch (StripeGatewayException $e) {
            // Not cached: the next look tries Stripe again.
            return ['error' => $e->getMessage()] + $none;
        }

        Cache::put($key, $out, self::CACHE_SECONDS);

        return $out;
    }

    /** After a sweep, so the page shows the new balances at once. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY.':'.(OperatingAccount::current()?->stripe_account_id ?? 'none'));
    }

    /** Available plus pending, US dollars only. */
    private static function total(array $balance): int
    {
        $usd = fn (array $rows) => (int) collect($rows)->where('currency', 'usd')->sum('amount');

        return $usd($balance['available'] ?? []) + $usd($balance['pending'] ?? []);
    }
}
