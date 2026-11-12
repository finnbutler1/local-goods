<?php

namespace App\Support;

use App\Models\CreditAllocation;
use App\Models\Employer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Who counts as a participating employee: the only definition in the code
 * (ADR 0042). docs/finance/MODEL.md defines them as "Employees who receive
 * credits each month". The employer platform fee is charged at the first
 * purchase of a month, usually before that month's credits go out, so it
 * counts the month before, in arrears: the different employees of this
 * employer who received credits (any occasion) in the previous calendar
 * month, Washington, DC time. Nothing in the app edits a credit
 * allocation after it is made, so a past month's count stays the same
 * (only Reset demo, which is local only, clears them).
 */
class ParticipatingEmployees
{
    /** Participating employees in the calendar month before $at. */
    public static function lastMonth(Employer $employer, CarbonInterface $at): int
    {
        $thisMonth = Carbon::instance($at)->setTimezone(config('fraud.timezone'))->startOfMonth();

        return self::inMonth($employer, $thisMonth->copy()->subMonthNoOverflow());
    }

    /** Participating employees in the calendar month (DC time) that starts at or contains $month. */
    public static function inMonth(Employer $employer, CarbonInterface $month): int
    {
        $start = Carbon::instance($month)->setTimezone(config('fraud.timezone'))->startOfMonth();
        $end = $start->copy()->addMonthNoOverflow();

        return CreditAllocation::where('employer_id', $employer->id)
            ->where('allocated_at', '>=', $start->copy()->utc())
            ->where('allocated_at', '<', $end->copy()->utc())
            ->distinct()
            ->count('employee_id');
    }
}
