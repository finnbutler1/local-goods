<?php

namespace App\Services\Payments;

use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Support\StripeMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides, when a redemption clears, whether it will be paid through Stripe
 * (ADR 0015). Only if Stripe test mode is on, the TransferGate allows it,
 * and a Stripe-paid purchase still has enough unclaimed money to fund it.
 * The employer's credits are one pool, so purchases are matched oldest
 * first; this way Stripe transfers can never add up to more than Stripe
 * actually received. Anything else (seeded history, simulated purchases,
 * fallback mode) stays simulated and is never sent to Stripe.
 *
 * The row is for the sale less the Local Goods merchant fee snapshotted on
 * it (ADR 0029): that is what is transferred, and the fee stays in the
 * platform's balance. The purchase is matched on the whole sale, the
 * credits actually spent. A sale whose fee is all of it has nothing to
 * transfer, so it gets no row.
 *
 * Call inside the transaction that clears the redemption, which must have
 * called lockPurchases first: on MySQL a transaction's snapshot starts at its
 * first plain SELECT, so a lock taken after one leaves unclaimedCents reading
 * from before a claim that committed while we waited, and the same money is
 * claimed twice. Lock order everywhere: employer, employee, purchases, then
 * everything else, so these locks always queue in the same direction. The
 * employer comes first because locking the purchases also locks the gap for a
 * new one, and a new purchase (CreditLedger::requestPurchase) takes the
 * employer's lock first, so the two can never overlap.
 */
class StripeFunding
{
    public function __construct(private TransferGate $gate) {}

    /** The first lock of a transaction that may claim(). Nothing to lock with Stripe off. */
    public function lockEmployer(int $employerId): void
    {
        if (StripeMode::active()) {
            Employer::whereKey($employerId)->lockForUpdate()->first();
        }
    }

    /** Locks the purchases claim() chooses from: after the employee's lock, before the transaction's first plain SELECT. Nothing to lock with Stripe off. */
    public function lockPurchases(int $employerId): void
    {
        if (StripeMode::active()) {
            $this->fundingPurchases($employerId)->lockForUpdate()->get();
        }
    }

    public function claim(Redemption $redemption): ?MerchantPayout
    {
        if (! StripeMode::active() || ! $this->gate->allows($redemption)) {
            return null;
        }

        if (MerchantPayout::where('redemption_id', $redemption->id)->exists()) {
            return null;
        }

        $employerId = $redemption->employee->employer_id;

        $purchase = $this->fundingPurchases($employerId)
            ->lockForUpdate()
            ->get()
            ->first(fn (CreditPurchase $p) => $p->unclaimedCents() >= $redemption->amount_cents);

        if ($purchase === null) {
            return null;
        }

        $fee = (int) $redemption->merchant_fee_cents;

        if ($redemption->amount_cents - $fee <= 0) {
            return null;
        }

        return MerchantPayout::create([
            'merchant_id' => $redemption->merchant_id,
            'amount_cents' => $redemption->amount_cents - $fee,
            'fee_cents' => $fee,
            'status' => MerchantPayout::STATUS_OWED,
            'redemption_id' => $redemption->id,
            'credit_purchase_id' => $purchase->id,
            'transfer_group' => $purchase->reference,
            'source_charge_id' => $purchase->stripe_charge_id,
        ]);
    }

    /** The employer's Stripe-paid purchases, oldest first. */
    private function fundingPurchases(int $employerId): Builder
    {
        return CreditPurchase::where('employer_id', $employerId)
            ->where('status', CreditPurchase::STATUS_PAID)
            ->whereNotNull('stripe_charge_id')
            ->orderBy('paid_at')->orderBy('id');
    }
}
