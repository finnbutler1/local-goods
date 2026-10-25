<?php

namespace App\Services;

use App\Enums\Occasion;
use App\Models\Correction;
use App\Models\CreditAllocation;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Support\MerchantFee;
use App\Support\Money;
use App\Support\PlatformFee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every money movement outside a redemption itself: buying credits, handing
 * them to employees, crediting merchants for cleared redemptions, and paying
 * merchants out. Each one writes a record and its ledger entries in one
 * transaction; balances are only ever sums of ledger entries.
 */
class CreditLedger
{
    public function purchase(Employer $employer, int $amountCents, string $reference, ?Carbon $at = null): CreditPurchase
    {
        $this->ensurePositive($amountCents);
        $at ??= now();

        return DB::transaction(function () use ($employer, $amountCents, $reference, $at) {
            $purchase = CreditPurchase::create([
                'employer_id' => $employer->id,
                'amount_cents' => $amountCents,
                'reference' => $reference,
                'purchased_at' => $at,
            ]);

            CreditLedgerEntry::create([
                'employer_id' => $employer->id,
                'amount_cents' => $amountCents,
                'type' => CreditLedgerEntry::TYPE_PURCHASE,
                'credit_purchase_id' => $purchase->id,
                'description' => 'Credits purchased ('.$reference.')',
                'created_at' => $at,
            ]);

            return $purchase;
        });
    }

    /**
     * The employer asks to buy credits. Only a pending purchase record is
     * written, never a ledger entry: until it is paid the credits can't be
     * handed out or spent (ADR 0014). The platform fee (0 unless it is on,
     * ADR 0021) is fixed here, so a later change to the setting never
     * changes what this purchase costs.
     */
    public function requestPurchase(Employer $employer, int $amountCents, string $reference, ?Carbon $at = null): CreditPurchase
    {
        $this->ensurePositive($amountCents);

        return CreditPurchase::create([
            'employer_id' => $employer->id,
            'amount_cents' => $amountCents,
            'platform_fee_cents' => PlatformFee::centsFor($amountCents),
            'reference' => $reference,
            'purchased_at' => $at ?? now(),
            'status' => CreditPurchase::STATUS_PENDING,
        ]);
    }

    /**
     * Pays a pending purchase. Simulated until Stripe test mode: no real
     * money moves. This is the only place a requested purchase reaches the
     * ledger, which unlocks its credits. Only flips a purchase that is still
     * pending, so paying twice (a double click, two tabs) writes one entry.
     */
    public function payPurchase(CreditPurchase $purchase, ?Carbon $at = null): CreditPurchase
    {
        $at ??= now();

        return DB::transaction(function () use ($purchase, $at) {
            $changed = CreditPurchase::whereKey($purchase->id)
                ->where('status', CreditPurchase::STATUS_PENDING)
                ->update(['status' => CreditPurchase::STATUS_SIMULATED]);

            $purchase->refresh();

            if ($changed === 0) {
                throw new InvalidArgumentException($purchase->isCancelled() ? 'That purchase was cancelled.' : 'That purchase is already paid.');
            }

            CreditLedgerEntry::create([
                'employer_id' => $purchase->employer_id,
                'amount_cents' => $purchase->amount_cents,
                'type' => CreditLedgerEntry::TYPE_PURCHASE,
                'credit_purchase_id' => $purchase->id,
                'description' => 'Credits purchased ('.$purchase->reference.'), paid (simulated, no real money)',
                'created_at' => $at,
            ]);
            $this->recordPlatformFee($purchase, $at, 'simulated, no real money');

            return $purchase;
        });
    }

    /**
     * Stripe confirmed (by a verified webhook) that a purchase was paid in
     * test mode. The only place a Stripe payment reaches the ledger. Like
     * payPurchase it only flips a purchase that is still pending, so a
     * replayed or duplicate event writes nothing. Refuses a payment whose
     * amount or currency doesn't match the purchase (credits plus any
     * platform fee). Returns false when the purchase was already paid or was
     * cancelled (nothing written).
     */
    public function markPurchasePaidByStripe(CreditPurchase $purchase, string $paymentIntentId, ?string $chargeId, int $amountCents, string $currency, ?Carbon $at = null): bool
    {
        if ($amountCents !== $purchase->totalCents() || strtolower($currency) !== config('services.stripe.currency')) {
            throw new InvalidArgumentException('The Stripe payment ('.Money::format($amountCents).' '.strtoupper($currency)
                .') does not match purchase '.$purchase->reference.' ('.Money::format($purchase->totalCents()).' USD).');
        }

        $at ??= now();

        return DB::transaction(function () use ($purchase, $paymentIntentId, $chargeId, $at) {
            $changed = CreditPurchase::whereKey($purchase->id)
                ->where('status', CreditPurchase::STATUS_PENDING)
                ->update([
                    'status' => CreditPurchase::STATUS_PAID,
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'stripe_charge_id' => $chargeId,
                    'paid_at' => $at,
                    'payment_error' => null,
                ]);

            $purchase->refresh();

            if ($changed === 0) {
                return false;
            }

            CreditLedgerEntry::create([
                'employer_id' => $purchase->employer_id,
                'amount_cents' => $purchase->amount_cents,
                'type' => CreditLedgerEntry::TYPE_PURCHASE,
                'credit_purchase_id' => $purchase->id,
                'description' => 'Credits purchased ('.$purchase->reference.'), paid by card in Stripe test mode ('.$paymentIntentId.')',
                'created_at' => $at,
            ]);
            $this->recordPlatformFee($purchase, $at, 'Stripe test mode, '.$paymentIntentId);

            return true;
        });
    }

    /**
     * The platform fee on its own line on the platform account (ADR 0021),
     * in the same transaction as the purchase entry. Nothing when the
     * purchase has no fee, so with the fee off the ledger is as before.
     */
    private function recordPlatformFee(CreditPurchase $purchase, Carbon $at, string $how): void
    {
        if ($purchase->platform_fee_cents <= 0) {
            return;
        }

        CreditLedgerEntry::create([
            'platform' => true,
            'amount_cents' => $purchase->platform_fee_cents,
            'type' => CreditLedgerEntry::TYPE_PLATFORM_FEE,
            'credit_purchase_id' => $purchase->id,
            'description' => 'Platform fee on '.$purchase->reference.' ('.$how.')',
            'created_at' => $at,
        ]);
    }

    /**
     * The employer dashboard's allocation: the same amount to each of these
     * employees, all or nothing. Refuses more than the employer's available
     * balance or what is left of this month's budget, before writing anything.
     *
     * @param  iterable<Employee>  $employees
     * @return list<CreditAllocation>
     */
    public function allocateMany(Employer $employer, iterable $employees, int $amountCents, Occasion $occasion, ?Carbon $at = null): array
    {
        $this->ensurePositive($amountCents);
        $employees = collect($employees)->values();

        if ($employees->isEmpty()) {
            throw new InvalidArgumentException('Choose at least one employee.');
        }

        $at ??= now();
        $total = $amountCents * $employees->count();

        return DB::transaction(function () use ($employer, $employees, $amountCents, $occasion, $at, $total) {
            $employer = Employer::whereKey($employer->id)->lockForUpdate()->firstOrFail();

            if ($total > $employer->balanceCents()) {
                throw new InvalidArgumentException('That needs '.Money::format($total).' but only '
                    .Money::format($employer->balanceCents()).' of paid credits is available. Buy more credits first.');
            }

            $remaining = EmployerProgram::remainingBudgetCents($employer, $at);

            if ($remaining !== null && $total > $remaining) {
                throw new InvalidArgumentException('That needs '.Money::format($total).' but only '
                    .Money::format($remaining).' is left in this month\'s budget.');
            }

            return $employees->map(fn (Employee $employee) => $this->allocate($employer, $employee, $amountCents, $at, $occasion))->all();
        });
    }

    /**
     * Moves credits from the employer to one employee. Refuses more than the
     * employer has bought and not yet handed out.
     */
    public function allocate(Employer $employer, Employee $employee, int $amountCents, ?Carbon $at = null, ?Occasion $occasion = null): CreditAllocation
    {
        $this->ensurePositive($amountCents);

        if ($employee->employer_id !== $employer->id) {
            throw new InvalidArgumentException('That employee does not work for this employer.');
        }

        $at ??= now();

        return DB::transaction(function () use ($employer, $employee, $amountCents, $at, $occasion) {
            Employer::whereKey($employer->id)->lockForUpdate()->first();

            if ($amountCents > $employer->balanceCents()) {
                throw new InvalidArgumentException('The employer has not bought enough credits for that.');
            }

            $allocation = CreditAllocation::create([
                'employer_id' => $employer->id,
                'employee_id' => $employee->id,
                'amount_cents' => $amountCents,
                'allocated_at' => $at,
                'occasion' => $occasion,
            ]);

            CreditLedgerEntry::create([
                'employer_id' => $employer->id,
                'amount_cents' => -$amountCents,
                'type' => CreditLedgerEntry::TYPE_ALLOCATION_OUT,
                'credit_allocation_id' => $allocation->id,
                'description' => 'Credits given to an employee',
                'created_at' => $at,
            ]);

            CreditLedgerEntry::create([
                'employee_id' => $employee->id,
                'amount_cents' => $amountCents,
                'type' => CreditLedgerEntry::TYPE_ALLOWANCE,
                'credit_allocation_id' => $allocation->id,
                'description' => $occasion === null || $occasion === Occasion::Monthly
                    ? 'Allowance from '.$employer->name.' ('.$at->copy()->setTimezone(config('fraud.timezone'))->format('F').')'
                    : $occasion->label().' from '.$employer->name,
                'created_at' => $at,
            ]);

            return $allocation;
        });
    }

    /**
     * The merchant is owed a redemption once it has cleared, never while it
     * is held. Called inside the transaction that clears it.
     *
     * The Local Goods merchant fee snapshotted on it (ADR 0029) moves from
     * the merchant to the platform account in the same transaction, as two
     * lines that add up to zero, so the merchant is owed the sale less the
     * fee. Nothing when the fee is 0 (always, until it is turned on), so the
     * ledger is then exactly as before.
     */
    public function creditMerchant(Redemption $redemption, ?Carbon $at = null): CreditLedgerEntry
    {
        if ($redemption->status !== Redemption::STATUS_APPROVED) {
            throw new InvalidArgumentException('Only a cleared redemption earns the merchant anything.');
        }

        $at ??= now();
        $earned = CreditLedgerEntry::create([
            'merchant_id' => $redemption->merchant_id,
            'amount_cents' => $redemption->amount_cents,
            'type' => CreditLedgerEntry::TYPE_MERCHANT_EARNED,
            'redemption_id' => $redemption->id,
            'description' => 'Cleared redemption of '.Money::format($redemption->amount_cents),
            'created_at' => $at,
        ]);

        if (($fee = (int) $redemption->merchant_fee_cents) > 0) {
            $base = ['redemption_id' => $redemption->id, 'created_at' => $at];
            CreditLedgerEntry::create($base + [
                'merchant_id' => $redemption->merchant_id,
                'amount_cents' => -$fee,
                'type' => CreditLedgerEntry::TYPE_MERCHANT_FEE,
                'description' => 'Local Goods fee of '.Money::format($fee).' on a cleared redemption of '.Money::format($redemption->amount_cents).' ('.MerchantFee::of($redemption)->label().')',
            ]);
            CreditLedgerEntry::create($base + [
                'platform' => true,
                'amount_cents' => $fee,
                'type' => CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE,
                'description' => 'Local Goods fee kept on redemption '.$redemption->reference(),
            ]);
        }

        return $earned;
    }

    /**
     * Stripe accepted a Transfer for a Stripe-channel payout row: the row is
     * sent and the merchant's account gets its one payout entry. Only flips
     * a row that is still pending, so it can't be recorded twice.
     */
    public function recordStripeTransfer(MerchantPayout $payout, string $transferId, ?Carbon $at = null): bool
    {
        $at ??= now();

        return DB::transaction(function () use ($payout, $transferId, $at) {
            $changed = MerchantPayout::whereKey($payout->id)
                ->where('status', MerchantPayout::STATUS_PENDING)
                ->update(['status' => MerchantPayout::STATUS_SENT, 'stripe_transfer_id' => $transferId, 'paid_at' => $at, 'failure_reason' => null]);

            $payout->refresh();

            if ($changed === 0) {
                return false;
            }

            CreditLedgerEntry::create([
                'merchant_id' => $payout->merchant_id,
                'amount_cents' => -$payout->amount_cents,
                'type' => CreditLedgerEntry::TYPE_PAYOUT,
                'merchant_payout_id' => $payout->id,
                // A merchant adjustment ("I wasn't paid", ADR 0028) that sent this row; null for every other transfer.
                'correction_id' => Correction::where('type', Correction::TYPE_MERCHANT_ADJUSTMENT)->where('merchant_payout_id', $payout->id)
                    ->whereIn('status', [Correction::STATUS_PROCESSING, Correction::STATUS_FAILED])->value('id'),
                'description' => 'Stripe test-mode transfer '.$transferId.' to '.$payout->merchant->name,
                'created_at' => $at,
            ]);

            return true;
        });
    }

    /**
     * Stripe reports a sent transfer was reversed (pulled back from the
     * merchant). The merchant is owed the amount again, through Stripe; it
     * is never re-sent automatically. Only flips a row that is still sent.
     */
    public function recordStripeReversal(MerchantPayout $payout, ?Carbon $at = null): bool
    {
        $at ??= now();

        return DB::transaction(function () use ($payout, $at) {
            $changed = MerchantPayout::whereKey($payout->id)
                ->where('status', MerchantPayout::STATUS_SENT)
                ->update(['status' => MerchantPayout::STATUS_REVERSED, 'reversed_at' => $at, 'failure_reason' => 'Reversed in Stripe. Owed again; a person decides what happens next.']);

            $payout->refresh();

            if ($changed === 0) {
                return false;
            }

            CreditLedgerEntry::create([
                'merchant_id' => $payout->merchant_id,
                'amount_cents' => $payout->amount_cents,
                'type' => CreditLedgerEntry::TYPE_PAYOUT_REVERSED,
                'merchant_payout_id' => $payout->id,
                'description' => 'Stripe test-mode transfer '.$payout->stripe_transfer_id.' reversed; owed again',
                'created_at' => $at,
            ]);

            return true;
        });
    }

    /**
     * A redemption refund has completed (ADR 0028): the employee gets the
     * credits back and the merchant's earning for that part is taken back.
     * With $reversedFrom, Stripe reversed that part of the merchant's
     * transfer, so the money that came back is its own merchant line and
     * the merchant nets to zero. Without it (simulated), the merchant simply
     * has less owed: deducted from its next simulated payout, which can
     * leave a merchant already paid out below zero until then. Every line
     * links the redemption and the correction. Called inside the
     * transaction that marks the correction's action done, so it is
     * written once.
     */
    public function refundRedemption(Correction $correction, int $amountCents, ?MerchantPayout $reversedFrom = null, ?string $reversalId = null, ?Carbon $at = null): void
    {
        $this->ensurePositive($amountCents);
        $redemption = $correction->redemption;
        $at ??= now();
        $how = $reversedFrom ? 'Stripe test mode, '.$reversalId : 'simulated, deducted from the merchant\'s next payout';
        $base = ['redemption_id' => $redemption->id, 'correction_id' => $correction->id, 'created_at' => $at];

        CreditLedgerEntry::create($base + [
            'employee_id' => $redemption->employee_id,
            'amount_cents' => $amountCents,
            'type' => CreditLedgerEntry::TYPE_REFUND,
            'description' => 'Refund from '.$redemption->merchant->name,
        ]);

        CreditLedgerEntry::create($base + [
            'merchant_id' => $redemption->merchant_id,
            'amount_cents' => -$amountCents,
            'type' => CreditLedgerEntry::TYPE_MERCHANT_REFUNDED,
            'description' => 'Refund of '.Money::format($amountCents).' on a cleared redemption ('.$how.')',
        ]);

        if ($reversedFrom !== null) {
            CreditLedgerEntry::create($base + [
                'merchant_id' => $redemption->merchant_id,
                'amount_cents' => $amountCents,
                'type' => CreditLedgerEntry::TYPE_REFUND_REVERSAL,
                'merchant_payout_id' => $reversedFrom->id,
                'description' => 'Stripe test-mode transfer '.$reversedFrom->stripe_transfer_id.' reversed by '.Money::format($amountCents).' for the refund ('.$reversalId.')',
            ]);
        }
    }

    /**
     * Unspent credits held for a refund to the employer (ADR 0028): they
     * leave the employer's balance at once, like a held redemption's
     * credits, so they can't be given to employees while the refund goes
     * through. Called inside the approval's transaction, after its checks.
     */
    public function holdEmployerRefund(Correction $correction, int $amountCents, ?Carbon $at = null): CreditLedgerEntry
    {
        $this->ensurePositive($amountCents);

        return CreditLedgerEntry::create([
            'employer_id' => $correction->employer_id,
            'amount_cents' => -$amountCents,
            'type' => CreditLedgerEntry::TYPE_EMPLOYER_REFUND_HOLD,
            'correction_id' => $correction->id,
            'description' => 'Unspent credits held for a refund of '.Money::format($amountCents),
            'created_at' => $at ?? now(),
        ]);
    }

    /**
     * One payment's part of an employer refund went through: Stripe's webhook
     * confirmed the card refund, or the simulated purchase was returned.
     * Amount 0, like review_approved: the hold already took the credits.
     */
    public function completeEmployerRefund(Correction $correction, CreditPurchase $purchase, int $amountCents, ?string $refundId = null, ?Carbon $at = null): CreditLedgerEntry
    {
        return CreditLedgerEntry::create([
            'employer_id' => $correction->employer_id,
            'amount_cents' => 0,
            'type' => CreditLedgerEntry::TYPE_EMPLOYER_REFUNDED,
            'credit_purchase_id' => $purchase->id,
            'correction_id' => $correction->id,
            'description' => Money::format($amountCents).' refunded on '.$purchase->reference
                .($refundId ? ' to the card (Stripe test mode, '.$refundId.')' : ' (simulated, no real money)'),
            'created_at' => $at ?? now(),
        ]);
    }

    /** A refund closed without happening (in full or in part): those held credits are the employer's again. */
    public function releaseEmployerRefund(Correction $correction, int $amountCents, ?Carbon $at = null): CreditLedgerEntry
    {
        $this->ensurePositive($amountCents);

        return CreditLedgerEntry::create([
            'employer_id' => $correction->employer_id,
            'amount_cents' => $amountCents,
            'type' => CreditLedgerEntry::TYPE_EMPLOYER_REFUND_RELEASED,
            'correction_id' => $correction->id,
            'description' => 'Refund closed: '.Money::format($amountCents).' of held credits back to give',
            'created_at' => $at ?? now(),
        ]);
    }

    /**
     * Pays a merchant out of what cleared redemptions earned it, with no
     * Stripe transfer (the seed's weekly payouts). Refuses more than the
     * simulated part of what the merchant is owed, so it can never pay out
     * money that is waiting for a Stripe transfer.
     */
    public function payout(Merchant $merchant, int $amountCents, string $status, ?Carbon $at = null): MerchantPayout
    {
        $this->ensurePositive($amountCents);
        $at ??= now();

        return DB::transaction(function () use ($merchant, $amountCents, $status, $at) {
            Merchant::whereKey($merchant->id)->lockForUpdate()->first();

            if ($amountCents > $merchant->payableCents()) {
                throw new InvalidArgumentException('That is more than the merchant is owed.');
            }

            if ($status === MerchantPayout::STATUS_SIMULATED && $amountCents > $merchant->owedSimulatedCents()) {
                throw new InvalidArgumentException('Part of that is owed through Stripe test mode, so it can\'t be paid out as simulated.');
            }

            $payout = MerchantPayout::create([
                'merchant_id' => $merchant->id,
                'amount_cents' => $amountCents,
                'status' => $status,
                'paid_at' => $at,
            ]);

            CreditLedgerEntry::create([
                'merchant_id' => $merchant->id,
                'amount_cents' => -$amountCents,
                'type' => CreditLedgerEntry::TYPE_PAYOUT,
                'merchant_payout_id' => $payout->id,
                'description' => 'Payout to '.$merchant->name.($status === MerchantPayout::STATUS_SIMULATED ? ' (simulated, no Stripe transfer)' : ''),
                'created_at' => $at,
            ]);

            return $payout;
        });
    }

    private function ensurePositive(int $amountCents): void
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Amounts must be more than zero.');
        }
    }
}
