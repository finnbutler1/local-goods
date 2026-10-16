<?php

namespace App\Services\Payments;

use App\Models\CreditLedgerEntry;
use App\Models\Redemption;

/**
 * The one question asked before any money leaves Stripe for a merchant:
 * may this redemption be paid out?
 *
 * PROMPT 8 FRAUD GATE: this is where Prompt 8's fraud gate will sit. Today
 * it allows only a redemption that has cleared (Redemption Fraud Detection
 * cleared it, or a BID admin approved it after a hold) and whose merchant
 * earning is in the ledger. Held, rejected or pending redemptions never
 * pass, so they never get a Stripe transfer. Every Stripe transfer goes
 * through StripeFunding::claim, which asks this first, and
 * MerchantPayouts::send, which asks it again just before sending.
 */
class TransferGate
{
    public function allows(Redemption $redemption): bool
    {
        return $redemption->status === Redemption::STATUS_APPROVED
            && CreditLedgerEntry::where('redemption_id', $redemption->id)
                ->where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)
                ->exists();
    }
}
