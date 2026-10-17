<?php

namespace App\Services\Payments;

use App\Models\CreditLedgerEntry;
use App\Models\Redemption;
use App\Models\RedemptionDecision;

/**
 * The fraud gate on transfers (ADR 0016): the one question asked before any
 * money leaves Stripe for a merchant. May this redemption be paid out?
 *
 * Yes only if it is approved, its merchant earning is in the ledger, and
 * either Redemption Fraud Detection itself cleared it (a real AI answer, no
 * person involved) or a person recorded an Approve after the AI held it.
 * Held, denied, unreviewed and seeded history never pass. An AI failure is
 * always a hold (RedemptionFraudDetector fails closed), so it only passes
 * after a person approves it.
 *
 * Every Stripe transfer goes through MerchantPayouts::sendOne, which asks
 * this just before calling Stripe; StripeFunding::claim asks it too before
 * a payout row is even made. TransferGateTest checks no other code path
 * can create a transfer.
 */
class TransferGate
{
    public function allows(Redemption $redemption): bool
    {
        // Read the row again: a stale copy must never open the gate.
        $redemption = Redemption::find($redemption->id);

        if ($redemption === null || $redemption->status !== Redemption::STATUS_APPROVED) {
            return false;
        }

        $earned = CreditLedgerEntry::where('redemption_id', $redemption->id)
            ->where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)
            ->exists();

        if (! $earned) {
            return false;
        }

        $decision = RedemptionDecision::where('redemption_id', $redemption->id)->first();

        return $decision !== null ? $decision->isApproval() : $redemption->wasClearedByAi();
    }
}
