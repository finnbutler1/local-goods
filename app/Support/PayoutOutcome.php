<?php

namespace App\Support;

use App\Models\MerchantPayout;
use App\Models\Redemption;

/**
 * One sentence saying what happened to the merchant's money after an
 * approval, by payout channel (ADR 0015): a Stripe test transfer sent, owed
 * through Stripe and why it hasn't gone yet, or simulated with no money moved.
 * Display only: reads the payout row, never sends, retries or writes.
 * "Paid" only ever means a sent Stripe test transfer.
 */
class PayoutOutcome
{
    public static function sentence(Redemption $redemption): string
    {
        $redemption->refresh()->load('stripePayout', 'merchant');
        $payout = $redemption->stripePayout;

        return match ($payout?->status) {
            null, MerchantPayout::STATUS_SIMULATED => 'Now owed to the merchant (simulated, no money moved).',
            MerchantPayout::STATUS_SENT => 'Stripe test transfer sent.',
            MerchantPayout::STATUS_PENDING => 'Stripe test transfer started; waiting for Stripe to confirm it.',
            MerchantPayout::STATUS_REVERSED => 'The Stripe test transfer was reversed, so nothing is with the merchant.',
            MerchantPayout::STATUS_OWED => $redemption->merchant->isStripeOnboarded()
                ? "Owed to the merchant through Stripe. The test transfer couldn't be made yet, so nothing moved."
                : "Owed to the merchant through Stripe. No transfer yet: they haven't finished Stripe onboarding.",
            default => "Owed to the merchant through Stripe. The test transfer couldn't be made yet, so nothing moved.",
        };
    }
}
