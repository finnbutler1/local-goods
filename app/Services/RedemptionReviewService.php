<?php

namespace App\Services;

use App\Enums\DenialReason;
use App\Enums\Role;
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
 * Who may decide is checked by RedemptionPolicy::review before this. A held
 * in-person payment can also be cancelled by its own merchant or an admin
 * (ADR 0026), through the same path as a "no purchase" denial.
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
        return $this->decide($redemption, $reviewer, $reason, RedemptionDecision::APPROVE, null, $reviewer->name, fn (Redemption $r) => [
            'employee_id' => $r->employee_id,
            'amount_cents' => 0,
            'type' => CreditLedgerEntry::TYPE_REVIEW_APPROVED,
            'description' => "Approved by {$reviewer->name}: reserved ".Money::format($r->amount_cents)." spent at {$r->merchant->name}",
        ]);
    }

    public function deny(Redemption $redemption, User $reviewer, string $reason, DenialReason $why): Redemption
    {
        return $this->decide($redemption, $reviewer, $reason, RedemptionDecision::DENY, $why->returnsTo(), $reviewer->name, fn (Redemption $r) => $why === DenialReason::SuspectedAbuse
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
     * The merchant (or a BID or platform admin) calls off a held in-person
     * payment because the customer paid another way (ADR 0026). The same return path as a "no
     * purchase happened" denial: one entry gives the employee the reserved
     * credits back, no transfer ever. Recorded as a cancel, not a fraud
     * denial. Who may cancel is RedemptionDecision::mayCancel; it can't be
     * approved afterwards, because only a held row can be decided.
     */
    public function cancel(Redemption $redemption, User $by): Redemption
    {
        $who = $by->role === Role::Merchant ? 'the merchant' : 'a '.$by->role->label();

        return $this->decide($redemption, $by, "Cancelled at the counter by {$who}: paid another way.", RedemptionDecision::CANCEL, 'employee', $who, fn (Redemption $r) => [
            'employee_id' => $r->employee_id,
            'amount_cents' => $r->amount_cents,
            'type' => CreditLedgerEntry::TYPE_REVIEW_REJECTED,
            'description' => "Cancelled at the counter by {$who}: ".Money::format($r->amount_cents)." returned from {$r->merchant->name}",
        ]);
    }

    /**
     * @param  string  $shownAs  the name kept on the redemption and its ledger entry: the
     *                           reviewer's for approve and deny, "the merchant" or "the employee" for a cancel
     * @param  callable(Redemption): array<string, mixed>  $entry
     */
    private function decide(Redemption $redemption, User $reviewer, string $reason, string $kind, ?string $returnsTo, string $shownAs, callable $entry): Redemption
    {
        $approve = $kind === RedemptionDecision::APPROVE;
        $status = $approve ? Redemption::STATUS_APPROVED : Redemption::STATUS_REJECTED;

        $redemption = DB::transaction(function () use ($redemption, $reviewer, $reason, $kind, $returnsTo, $shownAs, $approve, $status, $entry) {
            $now = now();

            // Only flips a row that is still under review, so a second decision
            // (a double click, two tabs, two reviewers) changes nothing.
            $changed = Redemption::whereKey($redemption->id)
                ->where('status', Redemption::STATUS_UNDER_REVIEW)
                ->update([
                    'status' => $status,
                    'reviewed_by' => $shownAs,
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
                'decision' => $kind,
                'reason' => trim($reason),
                'credit_returned_to' => $returnsTo,
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
                'decided_by' => $shownAs,
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
