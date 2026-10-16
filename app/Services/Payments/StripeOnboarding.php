<?php

namespace App\Services\Payments;

use App\Models\Merchant;
use Illuminate\Support\Str;

/**
 * Stripe Express onboarding for the fictional demo merchants (ADR 0015).
 * Real merchant candidates live only in data/merchant_candidates.csv and
 * are never Merchant rows, so they can never reach this. The merchant
 * fills in Stripe's own hosted form; Local Goods stores only the account
 * id and whether Stripe says it can receive transfers.
 */
class StripeOnboarding
{
    public function __construct(private StripeGateway $stripe) {}

    /** @return string Stripe's onboarding page for this merchant */
    public function link(Merchant $merchant): string
    {
        if ($merchant->stripe_account_id === null) {
            $merchant->stripe_onboarding_key ??= (string) Str::uuid();
            $merchant->save();

            $account = $this->stripe->createExpressAccount([
                'country' => 'US',
                'business_type' => 'company',
                'business_profile' => ['name' => $merchant->name, 'product_description' => 'Independent Golden Triangle business taking Local Goods credits (test mode)'],
                'capabilities' => ['transfers' => ['requested' => true]],
                'metadata' => ['lg_merchant' => $merchant->name],
            ], 'lg-account-'.$merchant->stripe_onboarding_key);

            $this->apply($merchant, $account);
        }

        return $this->stripe->createAccountLink(
            $merchant->stripe_account_id,
            route('merchant.stripe.refresh'),
            route('merchant.stripe.return'),
        )['url'];
    }

    /** Asks Stripe for the account's current status. Returns true if it just became onboarded. */
    public function refresh(Merchant $merchant): bool
    {
        if ($merchant->stripe_account_id === null) {
            return false;
        }

        return $this->apply($merchant, $this->stripe->retrieveAccount($merchant->stripe_account_id));
    }

    /**
     * Stores what Stripe says about the account (from the API or an
     * account.updated webhook). Returns true if it just became onboarded.
     */
    public function apply(Merchant $merchant, array $account): bool
    {
        $was = $merchant->isStripeOnboarded();

        $merchant->forceFill([
            'stripe_account_id' => $account['id'],
            'stripe_details_submitted' => (bool) ($account['details_submitted'] ?? false),
            'stripe_payouts_enabled' => (bool) ($account['payouts_enabled'] ?? false),
            'stripe_transfers_active' => ($account['capabilities']['transfers'] ?? null) === 'active',
            'stripe_synced_at' => now(),
        ])->save();

        return ! $was && $merchant->isStripeOnboarded();
    }
}
