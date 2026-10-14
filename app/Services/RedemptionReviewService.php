<?php

namespace App\Services;

use App\Exceptions\RedemptionAlreadyReviewed;
use App\Models\CreditLedgerEntry;
use App\Models\Redemption;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * A person's decision on a redemption that Redemption Fraud Detection held.
 * The credits were already debited (reserved) when the redemption was made:
 * approving spends them, rejecting gives them back. Each decision adds one
 * ledger entry recording who decided; nothing is edited or deleted. An
 * approval also credits the merchant, which a held redemption never does. Who may
 * decide is checked by RedemptionPolicy::review before this is called.
 */
class RedemptionReviewService
{
    public function __construct(private CreditLedger $ledger) {}

    public function approve(Redemption $redemption, User $reviewer): Redemption
    {
        return $this->decide($redemption, $reviewer, Redemption::STATUS_APPROVED, fn (Redemption $r) => [
            'amount_cents' => 0,
            'type' => CreditLedgerEntry::TYPE_REVIEW_APPROVED,
            'description' => "Approved by {$reviewer->name}: reserved ".Money::format($r->amount_cents)." spent at {$r->merchant->name}",
        ]);
    }

    public function reject(Redemption $redemption, User $reviewer): Redemption
    {
        return $this->decide($redemption, $reviewer, Redemption::STATUS_REJECTED, fn (Redemption $r) => [
            'amount_cents' => $r->amount_cents,
            'type' => CreditLedgerEntry::TYPE_REVIEW_REJECTED,
            'description' => "Rejected by {$reviewer->name}: ".Money::format($r->amount_cents)." returned from {$r->merchant->name}",
        ]);
    }

    /**
     * @param  callable(Redemption): array<string, mixed>  $entry
     */
    private function decide(Redemption $redemption, User $reviewer, string $status, callable $entry): Redemption
    {
        return DB::transaction(function () use ($redemption, $reviewer, $status, $entry) {
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

            CreditLedgerEntry::create($entry($redemption) + [
                'employee_id' => $redemption->employee_id,
                'redemption_id' => $redemption->id,
                'decided_by' => $reviewer->name,
                'created_at' => $now,
            ]);

            if ($status === Redemption::STATUS_APPROVED) {
                $this->ledger->creditMerchant($redemption, $now);
            }

            return $redemption;
        });
    }
}
