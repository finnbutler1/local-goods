<?php

namespace App\Support;

use App\Models\Correction;
use App\Models\Redemption;
use Carbon\CarbonInterface;

/**
 * What is waiting for a person to decide (ADR 0032): payments Redemption
 * Fraud Detection held, and refund requests nobody has decided yet. Shown
 * on the admin homes, the corrections queue and the demo activity page, so
 * the queue never looks neglected. Nothing is ever decided automatically:
 * past overdue_days an item is shown plainly as overdue, not hidden.
 */
final class WaitingForAPerson
{
    /**
     * @return array{held: int, held_oldest: ?CarbonInterface, refunds: int, refunds_oldest: ?CarbonInterface, overdue_days: int, overdue: bool, total: int}
     */
    public static function summary(): array
    {
        $held = Redemption::where('status', Redemption::STATUS_UNDER_REVIEW);
        $refunds = Correction::where('status', Correction::STATUS_REQUESTED);
        $heldOldest = (clone $held)->min('created_at');
        $refundsOldest = (clone $refunds)->min('created_at');
        $days = (int) config('demo_activity.queue.overdue_days', 3);
        $cutoff = now()->subDays($days);

        $summary = [
            'held' => $held->count(),
            'held_oldest' => $heldOldest ? \Illuminate\Support\Carbon::parse($heldOldest) : null,
            'refunds' => $refunds->count(),
            'refunds_oldest' => $refundsOldest ? \Illuminate\Support\Carbon::parse($refundsOldest) : null,
            'overdue_days' => $days,
        ];

        return $summary + [
            'overdue' => ($summary['held_oldest']?->lt($cutoff) ?? false) || ($summary['refunds_oldest']?->lt($cutoff) ?? false),
            'total' => $summary['held'] + $summary['refunds'],
        ];
    }

    /** "3 days", "5 hours", "12 minutes": how long the oldest has waited. */
    public static function age(CarbonInterface $since): string
    {
        return $since->diffForHumans(now(), ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1]);
    }
}
