<?php

namespace App\Services;

use App\Enums\DenialReason;
use App\Exceptions\RedemptionAlreadyReviewed;
use App\Models\CreditLedgerEntry;
use App\Models\Redemption;
use App\Models\RedemptionDecision;
use App\Models\User;
use App\Services\Payments\MerchantPayouts;
use App\Services\Payments\StripeFunding;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * A person's decision on a redemption that Redemption Fraud Detection held
 * (ADR 0016). The credits were already debited (reserved) when the
 * redemption was made. Approving spends them, credits the merchant and (if
 * Stripe-funded) sends its one Stripe transfer afterwards, through the
 * TransferGate. Denying never transfers: the credits go back to the
 * employer's pool for suspected abuse, or to the employee when no purchase
 * happened. Each decision writes one RedemptionDecision (who, when, why,
 * what the AI said) and one ledger entry; nothing is edited or deleted.
 * Who may decide is checked by RedemptionPolicy::review before this.
 */
class RedemptionReviewService
{
    public function __construct(
        private CreditLedger $ledger,
        private StripeFunding $funding,
        private MerchantPayouts $payouts,
    ) {}

    public function approve(Redemption $redemption, User $reviewer, string $reason): Redemption
    {
        return $this->decide($redemption, $reviewer, $reason, null, fn (Redemption $r) => [
            'employee_id' => $r->employee_id,
            'amount_cents' => 0,
            'type' => CreditLedgerEntry::TYPE_REVIEW_APPROVED,
            'description' => "Approved by {$reviewer->name}: reserved ".Money::format($r->amount_cents)." spent at {$r->merchant->name}",
        ]);
    }

    public function deny(Redemption $redemption, User $reviewer, string $reason, DenialReason $why): Redemption
    {
        return $this->decide($redemption, $reviewer, $reason, $why, fn (Redemption $r) => $why === DenialReason::SuspectedAbuse
            ? [
                'employer_id' => $r->employee->employer_id,
                'amount_cents' => $r->amount_cents,
                'type' => CreditLedgerEntry::TYPE_REVIEW_DENIED_TO_EMPLOYER,
                'description' => "Denied by {$reviewer->name} (suspected abuse): ".Money::format($r->amount_cents)." from {$r->merchant->name} back to the employer",
            ]
            : [
                'employee_id' => $r->employee_id,
                'amount_cents' => $r->amount_cents,
                'type' => CreditLedgerEntry::TYPE_REVIEW_REJECTED,
                'description' => "Denied by {$reviewer->name} (no purchase happened): ".Money::format($r->amount_cents)." returned from {$r->merchant->name}",
            ]);
    }

    /**
     * @param  callable(Redemption): array<string, mixed>  $entry
     */
    private function decide(Redemption $redemption, User $reviewer, string $reason, ?DenialReason $why, callable $entry): Redemption
    {
        $approve = $why === null;
        $status = $approve ? Redemption::STATUS_APPROVED : Redemption::STATUS_REJECTED;

        $redemption = DB::transaction(function () use ($redemption, $reviewer, $reason, $why, $approve, $status, $entry) {
            $now = now();

            // Only flips a row that is still under review, so a second decision
            // (a double click, two tabs, two reviewers) changes nothing.
            $changed = Redemption::whereKey($redemption->id)
                ->where('status', Redemption::STATUS_UNDER_REVIEW)
                ->update([
                    'status' => $status,
                    'reviewed_by' => $reviewer->name,
                    'reviewed_by_user_id' => $reviewer->id,
                    'reviewed_at' => $now,
                ]);

            $redemption->refresh();

            if ($changed === 0) {
                throw new RedemptionAlreadyReviewed($redemption);
            }

            // One per redemption (unique index): a second one rolls all of this back.
            RedemptionDecision::create([
                'redemption_id' => $redemption->id,
                'decision' => $approve ? RedemptionDecision::APPROVE : RedemptionDecision::DENY,
                'reason' => trim($reason),
                'credit_returned_to' => $why?->returnsTo(),
                'decided_by_user_id' => $reviewer->id,
                'decided_by_name' => $reviewer->name,
                'decided_by_role' => $reviewer->role->value,
                'ai_decision' => $redemption->ai_decision,
                'ai_risk_score' => $redemption->risk_score,
                'ai_scored_by' => $redemption->scored_by,
                'decided_at' => $now,
            ]);

            CreditLedgerEntry::create($entry($redemption) + [
                'redemption_id' => $redemption->id,
                'decided_by' => $reviewer->name,
                'created_at' => $now,
            ]);

            if ($approve) {
                $this->ledger->creditMerchant($redemption, $now);
                $this->funding->claim($redemption);
            }

            return $redemption;
        });

        // Outside the transaction, as in RedemptionService. Denied: never a transfer.
        if ($redemption->isApproved()) {
            $this->payouts->sendSafely($redemption->merchant);
        }

        return $redemption;
    }
}
