<?php

namespace App\Services\Payments;

use App\Models\CreditPurchase;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Support\StripeMode;

/**
 * Decides, when a redemption clears, whether it will be paid through Stripe
 * (ADR 0015). Only if Stripe test mode is on, the TransferGate allows it,
 * and a Stripe-paid purchase still has enough unclaimed money to fund it.
 * The employer's credits are one pool, so purchases are matched oldest
 * first; this way Stripe transfers can never add up to more than Stripe
 * actually received. Anything else (seeded history, simulated purchases,
 * fallback mode) stays simulated and is never sent to Stripe.
 *
 * Call inside the transaction that clears the redemption.
 */
class StripeFunding
{
    public function __construct(private TransferGate $gate) {}

    public function claim(Redemption $redemption): ?MerchantPayout
    {
        if (! StripeMode::active() || ! $this->gate->allows($redemption)) {
            return null;
        }

        if (MerchantPayout::where('redemption_id', $redemption->id)->exists()) {
            return null;
        }

        $employerId = $redemption->employee->employer_id;

        $purchase = CreditPurchase::where('employer_id', $employerId)
            ->where('status', CreditPurchase::STATUS_PAID)
            ->whereNotNull('stripe_charge_id')
            ->orderBy('paid_at')->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->first(fn (CreditPurchase $p) => $p->unclaimedCents() >= $redemption->amount_cents);

        if ($purchase === null) {
            return null;
        }

        return MerchantPayout::create([
            'merchant_id' => $redemption->merchant_id,
            'amount_cents' => $redemption->amount_cents,
            'status' => MerchantPayout::STATUS_OWED,
            'redemption_id' => $redemption->id,
            'credit_purchase_id' => $purchase->id,
            'transfer_group' => $purchase->reference,
            'source_charge_id' => $purchase->stripe_charge_id,
        ]);
    }
}
