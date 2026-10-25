<?php

namespace App\Services;

use App\Models\Employer;
use App\Models\Merchant;
use App\Models\Redemption;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Your team got out into the neighborhood": the employer dashboard's
 * numbers. Only cleared redemptions count (approved by the AI or by a person
 * at the BID); held and rejected ones are left out and counted separately.
 * "This month" is the calendar month so far in Washington, DC time. Each
 * figure's explanation on the page says the same as the code here.
 *
 * Money spent is net of completed refunds (ADR 0028): a refund comes off in
 * the month and week it completed (CompletedRefunds), and the category of
 * its merchant. A refund still processing doesn't count yet. Visits and
 * people are not changed by a refund: the visit happened.
 */
class EmployerMetrics
{
    public const TREND_WEEKS = 8;

    /**
     * @return array{
     *     enrolled: int, staff: ?int, redeemers: int, participation_pct: int, staff_pct: ?int, visits: int, businesses: int, participating: int,
     *     first_visits: int, spent_cents: int, refunded_cents: int, refunded_by_category: array<string, int>, held_count: int, held_cents: int,
     *     categories: list<array{category: string, cents: int, visits: int}>,
     *     weeks: list<array{start: Carbon, visits: int, cents: int, refunded_cents: int}>, month: Carbon
     * }
     */
    public function for(Employer $employer, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->setTimezone(config('fraud.timezone'));
        [$monthStart] = EmployerProgram::month($now);
        $trendStart = $now->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(self::TREND_WEEKS - 1);
        $from = $monthStart->copy()->min($trendStart->copy()->utc());

        $employeeIds = $employer->employees()->pluck('id');
        $cleared = Redemption::with('merchant:id,category')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', Redemption::STATUS_APPROVED)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $now->copy()->utc())
            ->get(['id', 'employee_id', 'merchant_id', 'amount_cents', 'created_at']);

        $thisMonth = $cleared->filter(fn (Redemption $r) => $r->created_at->gte($monthStart))->values();
        $refunds = CompletedRefunds::rows($from, $now, $employeeIds);
        $monthRefunds = $refunds->filter(fn ($row) => $row->at->gte($monthStart))->values();
        $held = Redemption::whereIn('employee_id', $employeeIds)
            ->where('status', Redemption::STATUS_UNDER_REVIEW)
            ->where('created_at', '>=', $monthStart)
            ->get(['amount_cents']);

        $enrolled = $employeeIds->count();
        $redeemers = $thisMonth->pluck('employee_id')->unique()->count();

        return [
            'month' => $now->copy()->startOfMonth(),
            'enrolled' => $enrolled,
            'staff' => $employer->staff_count,
            'redeemers' => $redeemers,
            'participation_pct' => $enrolled === 0 ? 0 : (int) round(100 * $redeemers / $enrolled),
            'staff_pct' => $employer->staff_count ? (int) round(100 * $redeemers / $employer->staff_count) : null,
            'visits' => $thisMonth->count(),
            'businesses' => $thisMonth->pluck('merchant_id')->unique()->count(),
            'participating' => Merchant::where('bid_id', $employer->bid_id)->count(),
            'first_visits' => $this->firstVisits($thisMonth, $employeeIds, $monthStart),
            'spent_cents' => (int) $thisMonth->sum('amount_cents') - (int) $monthRefunds->sum('cents'),
            'refunded_cents' => (int) $monthRefunds->sum('cents'),
            'refunded_by_category' => $monthRefunds->groupBy('category')->map(fn ($rows) => (int) $rows->sum('cents'))->all(),
            'held_count' => $held->count(),
            'held_cents' => (int) $held->sum('amount_cents'),
            'categories' => $this->categories($thisMonth, $monthRefunds),
            'weeks' => $this->weeks($cleared, $trendStart, $refunds),
        ];
    }

    /**
     * Visits this month where that employee had never had a cleared
     * redemption at that business before: a place they discovered.
     */
    private function firstVisits(Collection $thisMonth, Collection $employeeIds, Carbon $monthStart): int
    {
        $before = Redemption::whereIn('employee_id', $employeeIds)
            ->where('status', Redemption::STATUS_APPROVED)
            ->where('created_at', '<', $monthStart)
            ->distinct()->get(['employee_id', 'merchant_id'])
            ->map(fn ($r) => $r->employee_id.'-'.$r->merchant_id)->flip();

        return $thisMonth->map(fn (Redemption $r) => $r->employee_id.'-'.$r->merchant_id)
            ->unique()->reject(fn (string $pair) => $before->has($pair))->count();
    }

    /** @return list<array{category: string, cents: int, visits: int}> Highest spend first, net of this month's completed refunds. */
    private function categories(Collection $thisMonth, Collection $refunds): array
    {
        $visits = $thisMonth->groupBy(fn (Redemption $r) => $r->merchant->category);
        $refunded = $refunds->groupBy('category');

        return $visits->keys()->merge($refunded->keys())->unique()
            ->map(fn (string $category) => [
                'category' => $category,
                'cents' => (int) $visits->get($category, collect())->sum('amount_cents') - (int) $refunded->get($category, collect())->sum('cents'),
                'visits' => $visits->get($category, collect())->count(),
            ])
            ->sortByDesc('cents')->values()->all();
    }

    /**
     * Monday-to-Sunday weeks, oldest first; the last is this week so far.
     * cents is net of refunds completed that week.
     *
     * @return list<array{start: Carbon, visits: int, cents: int, refunded_cents: int}>
     */
    private function weeks(Collection $cleared, Carbon $trendStart, Collection $refunds): array
    {
        $tz = config('fraud.timezone');
        $byWeek = $cleared->groupBy(fn (Redemption $r) => $r->created_at->copy()->setTimezone($tz)->startOfWeek(Carbon::MONDAY)->toDateString());
        $refundedByWeek = $refunds->groupBy(fn ($row) => CompletedRefunds::weekOf($row));

        return collect(range(0, self::TREND_WEEKS - 1))->map(function (int $i) use ($trendStart, $byWeek, $refundedByWeek) {
            $start = $trendStart->copy()->addWeeks($i);
            $week = $byWeek->get($start->toDateString(), collect());
            $refunded = (int) $refundedByWeek->get($start->toDateString(), collect())->sum('cents');

            return ['start' => $start, 'visits' => $week->count(), 'cents' => (int) $week->sum('amount_cents') - $refunded, 'refunded_cents' => $refunded];
        })->all();
    }
}
