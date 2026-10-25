<?php

namespace App\Services;

use App\Models\CreditLedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Completed redemption refunds (ADR 0028), for netting them out of the
 * spending figures on the BID impact, participation and employer pages.
 * Read from the ledger: a merchant_refunded line is written only when a
 * refund completes (Stripe's webhook confirmed it, or a simulated one was
 * approved), never while one is processing, and it is dated then. So a
 * refund comes off in the week and month it completed, in DC time, not in
 * the week of the original visit. Display only: nothing here moves money.
 */
class CompletedRefunds
{
    /**
     * @param  ?iterable<int>  $employeeIds  only these employees' redemptions (one employer)
     * @return Collection<int, object{cents: int, merchant_id: int, employee_id: int, employer_id: int, category: string, at: Carbon}>
     */
    public static function rows(?Carbon $from = null, ?Carbon $to = null, ?iterable $employeeIds = null): Collection
    {
        return CreditLedgerEntry::query()
            ->where('credit_ledger_entries.type', CreditLedgerEntry::TYPE_MERCHANT_REFUNDED)
            ->join('redemptions', 'redemptions.id', '=', 'credit_ledger_entries.redemption_id')
            ->join('employees', 'employees.id', '=', 'redemptions.employee_id')
            ->join('merchants', 'merchants.id', '=', 'credit_ledger_entries.merchant_id')
            ->when($from, fn ($q) => $q->where('credit_ledger_entries.created_at', '>=', $from->copy()->utc()))
            ->when($to, fn ($q) => $q->where('credit_ledger_entries.created_at', '<=', $to->copy()->utc()))
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('redemptions.employee_id', collect($employeeIds)->all()))
            ->select([
                'credit_ledger_entries.amount_cents', 'credit_ledger_entries.merchant_id', 'credit_ledger_entries.created_at as refunded_at',
                'redemptions.employee_id', 'employees.employer_id', 'merchants.category',
            ])
            ->toBase()->get()
            ->map(fn ($row) => (object) [
                'cents' => -(int) $row->amount_cents,
                'merchant_id' => (int) $row->merchant_id,
                'employee_id' => (int) $row->employee_id,
                'employer_id' => (int) $row->employer_id,
                'category' => $row->category,
                'at' => Carbon::parse($row->refunded_at, 'UTC'),
            ]);
    }

    /** Monday (DC time) of the week a refund completed, as Y-m-d. */
    public static function weekOf(object $row): string
    {
        return $row->at->copy()->setTimezone(config('fraud.timezone'))->startOfWeek(Carbon::MONDAY)->toDateString();
    }
}
