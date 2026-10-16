<?php

namespace App\Services;

use App\Models\CreditPurchase;
use App\Models\Employer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The employer's program settings in numbers: how much of this month's
 * budget is used, what is still pending payment, and purchase references.
 * Months are calendar months in Washington, DC time.
 */
class EmployerProgram
{
    /** [start, end) of the calendar month containing $at, in UTC for queries. */
    public static function month(?Carbon $at = null): array
    {
        $local = ($at ?? now())->copy()->setTimezone(config('fraud.timezone'));

        return [$local->copy()->startOfMonth()->utc(), $local->copy()->startOfMonth()->addMonth()->utc()];
    }

    /** Credits handed to employees in the month containing $at. */
    public static function allocatedInMonthCents(Employer $employer, ?Carbon $at = null): int
    {
        [$start, $end] = self::month($at);

        return (int) $employer->creditAllocations()->where('allocated_at', '>=', $start)->where('allocated_at', '<', $end)->sum('amount_cents');
    }

    /** What is left of the monthly budget, never below zero, or null when no budget is set. */
    public static function remainingBudgetCents(Employer $employer, ?Carbon $at = null): ?int
    {
        if ($employer->monthly_budget_cents === null) {
            return null;
        }

        return max(0, $employer->monthly_budget_cents - self::allocatedInMonthCents($employer, $at));
    }

    /** Purchases requested but not yet paid: not in the ledger, so not usable. */
    public static function pendingCents(Employer $employer): int
    {
        return (int) $employer->creditPurchases()->where('status', CreditPurchase::STATUS_PENDING)->sum('amount_cents');
    }

    /** e.g. "MPG-20261015-2" for Meridian Policy Group's second purchase today. */
    public static function nextReference(Employer $employer, ?Carbon $at = null): string
    {
        $local = ($at ?? now())->copy()->setTimezone(config('fraud.timezone'));
        $prefix = Str::of($employer->name)->explode(' ')->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('')
            .'-'.$local->format('Ymd').'-';
        $today = $employer->creditPurchases()->where('reference', 'like', $prefix.'%')->count();

        return $prefix.($today + 1);
    }
}
