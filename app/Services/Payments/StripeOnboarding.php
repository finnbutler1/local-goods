<?php

namespace App\Services\Payments;

use App\Models\Merchant;
use Illuminate\Support\Str;

/**
 * Stripe Express onboarding for the fictional demo merchants (ADR 0015),
 * on Accounts v2 with the Express dashboard.
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

            // Accounts v2 (Stripe refuses new v1 Express accounts here), Express
            // dashboard, set up only to receive transfers. The "v2" in the key
            // keeps it apart from the refused v1 attempts, whose key Stripe
            // has stored with that refusal (ADR 0015).
            $account = $this->stripe->createExpressAccount([
                'display_name' => $merchant->name,
                'contact_email' => $this->contactEmail($merchant),
                'identity' => ['country' => 'US'],
                'defaults' => ['responsibilities' => ['fees_collector' => 'application', 'losses_collector' => 'application']],
                'configuration' => ['recipient' => ['capabilities' => ['stripe_balance' => ['stripe_transfers' => ['requested' => true]]]]],
                'metadata' => ['lg_merchant' => $merchant->name],
            ], 'lg-account-v2-'.$merchant->stripe_onboarding_key);

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
     * Stores what Stripe says about the account. Returns true if it just
     * became onboarded. Reads both shapes: Accounts v2 (what we create and
     * retrieve) and v1 (the account.updated webhook's snapshot).
     */
    public function apply(Merchant $merchant, array $account): bool
    {
        $was = $merchant->isStripeOnboarded();

        $status = isset($account['configuration']) || ($account['object'] ?? null) === 'v2.core.account'
            ? self::v2Status($account)
            : [
                'stripe_details_submitted' => (bool) ($account['details_submitted'] ?? false),
                'stripe_payouts_enabled' => (bool) ($account['payouts_enabled'] ?? false),
                'stripe_transfers_active' => ($account['capabilities']['transfers'] ?? null) === 'active',
            ];

        $merchant->forceFill(['stripe_account_id' => $account['id'], 'stripe_synced_at' => now()] + $status)->save();

        return ! $was && $merchant->isStripeOnboarded();
    }

    /** @return array<string, bool> */
    private static function v2Status(array $account): array
    {
        $balance = $account['configuration']['recipient']['capabilities']['stripe_balance'] ?? [];
        $waitingOnMerchant = collect($account['requirements']['entries'] ?? [])
            ->contains(fn ($entry) => ($entry['awaiting_action_from'] ?? null) === 'user');

        return [
            'stripe_details_submitted' => ! $waitingOnMerchant,
            'stripe_payouts_enabled' => ($balance['payouts']['status'] ?? null) === 'active',
            'stripe_transfers_active' => ($balance['stripe_transfers']['status'] ?? null) === 'active',
        ];
    }

    /** Stripe requires a contact email on a v2 account that receives transfers. Fictional for the demo merchants. */
    private function contactEmail(Merchant $merchant): string
    {
        return $merchant->user?->email
            ?? 'merchant-'.$merchant->id.'@example.com';
    }
}
