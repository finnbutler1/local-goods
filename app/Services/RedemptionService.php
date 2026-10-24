<?php

namespace App\Services;

use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Fraud\RedemptionFraudDetector;
use App\Services\Fraud\SignalCalculator;
use App\Services\Payments\MerchantPayouts;
use App\Services\Payments\StripeFunding;
use App\Support\TaxBreakdown;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class RedemptionService
{
    public function __construct(
        private SignalCalculator $signals,
        private RedemptionFraudDetector $detector,
        private CreditLedger $ledger,
        private StripeFunding $funding,
        private MerchantPayouts $payouts,
    ) {}

    /**
     * Score a redemption with Redemption Fraud Detection, then record it and
     * debit the ledger. Held redemptions still debit, so the credits stay
     * reserved while a person reviews them. Only a cleared redemption
     * credits the merchant; a held one earns it nothing until approved. A
     * cleared one funded by Stripe-paid credits is then transferred to the
     * merchant through Stripe (test mode), after the transaction commits.
     *
     * $items (from RedemptionBasket) are display only (ADR 0023): they are
     * stored with the redemption after scoring and never reach the signals
     * or the AI, which see exactly the amount and nothing else. $channel
     * ("to_go" or "in_person", ADR 0026) is the same: stored on the row for
     * display, never passed to the signals or the AI.
     *
     * $tax (ADR 0027) is the DC sales tax snapshot, stored on the row in the
     * same transaction. $amountCents is the total including any tax: it is
     * what the AI sees, what the ledger debits and what the merchant is
     * paid, exactly as before. Without $tax the amount is a typed-in shop
     * total with any tax already in it. The tax is display only and never
     * reaches the signals or the AI.
     *
     * @param  list<array{merchant_product_id: ?int, name: string, unit_price_cents: int, quantity: int, tax_category?: ?string, tax_rate_bps?: ?int}>  $items
     */
    public function redeem(Employee $employee, Merchant $merchant, int $amountCents, array $items = [], string $channel = Redemption::CHANNEL_TO_GO, ?TaxBreakdown $tax = null): Redemption
    {
        $tax ??= TaxBreakdown::included($amountCents);

        if ($tax->totalCents !== $amountCents
            || ($items !== [] && array_sum(array_map(fn ($i) => $i['unit_price_cents'] * $i['quantity'], $items)) + ($tax->taxCents ?? 0) !== $amountCents)) {
            throw new LogicException('The items and tax must add up to the redemption amount.');
        }

        $this->ensureAffordable($employee, $amountCents);

        $signals = $this->signals->calculate($employee, $merchant, $amountCents);

        // The AI call happens outside the transaction so the database is never locked while we wait.
        $verdict = $this->detector->score($signals);

        $redemption = DB::transaction(function () use ($employee, $merchant, $amountCents, $items, $channel, $tax, $signals, $verdict) {
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
                'channel' => $channel,
                ...$tax->columns(),
            ]);

            foreach ($items as $item) {
                $redemption->items()->create($item);
            }

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
                // Stripe-funded? Then it's owed through Stripe (ADR 0015); otherwise it stays simulated.
                $this->funding->claim($redemption);
            }

            return $redemption;
        });

        // Outside the transaction: a Stripe problem never undoes or blocks the redemption.
        if ($redemption->status === Redemption::STATUS_APPROVED) {
            $this->payouts->sendSafely($merchant);
        }

        return $redemption;
    }

    private function ensureAffordable(Employee $employee, int $amountCents): void
    {
        if ($amountCents > $employee->balanceCents()) {
            throw ValidationException::withMessages(['amount' => 'That is more than the available balance.']);
        }
    }
}
