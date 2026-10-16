<?php

namespace App\Services;

use App\Enums\Occasion;
use App\Models\CreditAllocation;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Support\Money;
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
     * handed out or spent (ADR 0014).
     */
    public function requestPurchase(Employer $employer, int $amountCents, string $reference, ?Carbon $at = null): CreditPurchase
    {
        $this->ensurePositive($amountCents);

        return CreditPurchase::create([
            'employer_id' => $employer->id,
            'amount_cents' => $amountCents,
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
                throw new InvalidArgumentException('That purchase is already paid.');
            }

            CreditLedgerEntry::create([
                'employer_id' => $purchase->employer_id,
                'amount_cents' => $purchase->amount_cents,
                'type' => CreditLedgerEntry::TYPE_PURCHASE,
                'credit_purchase_id' => $purchase->id,
                'description' => 'Credits purchased ('.$purchase->reference.'), paid (simulated, no real money)',
                'created_at' => $at,
            ]);

            return $purchase;
        });
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
     */
    public function creditMerchant(Redemption $redemption, ?Carbon $at = null): CreditLedgerEntry
    {
        if ($redemption->status !== Redemption::STATUS_APPROVED) {
            throw new InvalidArgumentException('Only a cleared redemption earns the merchant anything.');
        }

        return CreditLedgerEntry::create([
            'merchant_id' => $redemption->merchant_id,
            'amount_cents' => $redemption->amount_cents,
            'type' => CreditLedgerEntry::TYPE_MERCHANT_EARNED,
            'redemption_id' => $redemption->id,
            'description' => 'Cleared redemption of '.Money::format($redemption->amount_cents),
            'created_at' => $at ?? now(),
        ]);
    }

    /**
     * Pays a merchant out of what cleared redemptions earned it. Refuses
     * more than the merchant is owed.
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
