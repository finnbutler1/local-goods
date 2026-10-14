<?php

namespace App\Services;

use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Fraud\RedemptionFraudDetector;
use App\Services\Fraud\SignalCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RedemptionService
{
    public function __construct(
        private SignalCalculator $signals,
        private RedemptionFraudDetector $detector,
        private CreditLedger $ledger,
    ) {}

    /**
     * Score a redemption with Redemption Fraud Detection, then record it and
     * debit the ledger. Held redemptions still debit, so the credits stay
     * reserved while a person reviews them. Only a cleared redemption
     * credits the merchant; a held one earns it nothing until approved.
     */
    public function redeem(Employee $employee, Merchant $merchant, int $amountCents): Redemption
    {
        $this->ensureAffordable($employee, $amountCents);

        $signals = $this->signals->calculate($employee, $merchant, $amountCents);

        // The AI call happens outside the transaction so the database is never locked while we wait.
        $verdict = $this->detector->score($signals);

        return DB::transaction(function () use ($employee, $merchant, $amountCents, $signals, $verdict) {
            // Lock the employee row so two redemptions at once can't both pass
            // the balance check on MySQL. SQLite ignores this; its IMMEDIATE
            // transactions already let only one writer in at a time.
            Employee::whereKey($employee->id)->lockForUpdate()->first();

            // Re-check inside the transaction in case another redemption landed meanwhile.
            $this->ensureAffordable($employee, $amountCents);

            $redemption = Redemption::create([
                'employee_id' => $employee->id,
                'merchant_id' => $merchant->id,
                'amount_cents' => $amountCents,
                'status' => $verdict->isClear() ? Redemption::STATUS_APPROVED : Redemption::STATUS_UNDER_REVIEW,
                'risk_score' => $verdict->riskScore,
                'ai_decision' => $verdict->decision,
                'ai_explanation' => $verdict->explanation,
                'signals' => $signals,
                'scored_by' => $verdict->scoredBy,
            ]);

            CreditLedgerEntry::create([
                'employee_id' => $employee->id,
                'amount_cents' => -$amountCents,
                'type' => CreditLedgerEntry::TYPE_REDEMPTION,
                'redemption_id' => $redemption->id,
                'description' => 'Redemption at '.$merchant->name,
                'created_at' => now(),
            ]);

            if ($redemption->status === Redemption::STATUS_APPROVED) {
                $this->ledger->creditMerchant($redemption);
            }

            return $redemption;
        });
    }

    private function ensureAffordable(Employee $employee, int $amountCents): void
    {
        if ($amountCents > $employee->balanceCents()) {
            throw ValidationException::withMessages(['amount' => 'That is more than the available balance.']);
        }
    }
}
