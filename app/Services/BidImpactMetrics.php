<?php

namespace App\Services;

use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\Redemption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The BID impact page's numbers, read from the ledger only (ADR 0017). Each
 * dollar is a merchant_earned entry: the one entry CreditLedger writes when a
 * redemption clears, never while it is held. Entries are dated by when the
 * redemption happened (not when it cleared) in Washington, DC time, the same
 * as EmployerMetrics, so the BID and HR dashboards always agree. Employees
 * are only ever counted, never named.
 */
class BidImpactMetrics
{
    public function __construct(private EmployerMetrics $employerMetrics) {}

    /**
     * @return array{
     *     month: Carbon, has_activity: bool,
     *     this_month: array{spent_cents: int, visits: int, merchants: int, employees: int, employers: int},
     *     all_time: array{spent_cents: int, visits: int, merchants: int, employees: int, employers: int},
     *     enrolled: int, employers_total: int, merchants_total: int, held_count: int, held_cents: int,
     *     weeks: list<array{start: Carbon, visits: int, cents: int}>,
     *     employers: list<array{employer: Employer, metrics: array<string, mixed>}>,
     *     recirculation_cents: ?int
     * }
     */
    public function for(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->setTimezone(config('fraud.timezone'));
        [$monthStart] = EmployerProgram::month($now);
        $trendStart = $now->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(EmployerMetrics::TREND_WEEKS - 1);
        $from = $monthStart->copy()->min($trendStart->copy()->utc());

        $recent = $this->earned()
            ->select([
                'credit_ledger_entries.amount_cents', 'credit_ledger_entries.merchant_id',
                'redemptions.employee_id', 'employees.employer_id', 'redemptions.created_at as happened_at',
            ])
            ->where('redemptions.created_at', '>=', $from)
            ->where('redemptions.created_at', '<=', $now->copy()->utc())
            ->toBase()->get()
            ->each(fn ($row) => $row->happened_at = Carbon::parse($row->happened_at, 'UTC'));
        $thisMonth = $recent->filter(fn ($row) => $row->happened_at->gte($monthStart))->values();

        $held = Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->where('created_at', '>=', $monthStart)->get(['amount_cents']);
        $allTime = $this->totals();

        return [
            'month' => $now->copy()->startOfMonth(),
            'has_activity' => $allTime['visits'] > 0,
            'this_month' => $this->summarise($thisMonth),
            'all_time' => $allTime,
            'enrolled' => Employee::count(),
            'employers_total' => Employer::count(),
            'merchants_total' => Merchant::count(),
            'held_count' => $held->count(),
            'held_cents' => (int) $held->sum('amount_cents'),
            'weeks' => $this->weeks($recent, $trendStart),
            'employers' => Employer::orderBy('id')->get()
                ->map(fn (Employer $employer) => ['employer' => $employer, 'metrics' => $this->employerMetrics->for($employer, $now)])
                ->all(),
            'recirculation_cents' => $allTime['spent_cents'] > 0 ? self::recirculationCents($allTime['spent_cents']) : null,
        ];
    }

    /**
     * Since launch, straight from the ledger: what the landing page shows.
     *
     * @return array{spent_cents: int, visits: int, merchants: int, employees: int, employers: int}
     */
    public function totals(): array
    {
        $row = $this->earned()->toBase()->selectRaw(
            'COALESCE(SUM(credit_ledger_entries.amount_cents), 0) AS spent_cents, COUNT(*) AS visits, '
            .'COUNT(DISTINCT credit_ledger_entries.merchant_id) AS merchants, COUNT(DISTINCT redemptions.employee_id) AS employees, '
            .'COUNT(DISTINCT employees.employer_id) AS employers'
        )->first();

        return [
            'spent_cents' => (int) $row->spent_cents,
            'visits' => (int) $row->visits,
            'merchants' => (int) $row->merchants,
            'employees' => (int) $row->employees,
            'employers' => (int) $row->employers,
        ];
    }

    /** "If the national study's rate held here": an outside study's rate, never measured by Local Goods. */
    public static function recirculationCents(int $spentCents): int
    {
        return (int) round($spentCents * config('impact.recirculation.independent_rate'));
    }

    /** Every merchant_earned ledger entry, joined to its redemption and that employee for the employer and date. */
    private function earned(): Builder
    {
        return CreditLedgerEntry::query()
            ->where('credit_ledger_entries.type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)
            ->join('redemptions', 'redemptions.id', '=', 'credit_ledger_entries.redemption_id')
            ->join('employees', 'employees.id', '=', 'redemptions.employee_id');
    }

    /** @return array{spent_cents: int, visits: int, merchants: int, employees: int, employers: int} */
    private function summarise(Collection $rows): array
    {
        return [
            'spent_cents' => (int) $rows->sum('amount_cents'),
            'visits' => $rows->count(),
            'merchants' => $rows->pluck('merchant_id')->unique()->count(),
            'employees' => $rows->pluck('employee_id')->unique()->count(),
            'employers' => $rows->pluck('employer_id')->unique()->count(),
        ];
    }

    /** @return list<array{start: Carbon, visits: int, cents: int}> Monday-to-Sunday weeks, oldest first; the last is this week so far. */
    private function weeks(Collection $rows, Carbon $trendStart): array
    {
        $tz = config('fraud.timezone');
        $byWeek = $rows->groupBy(fn ($row) => $row->happened_at->copy()->setTimezone($tz)->startOfWeek(Carbon::MONDAY)->toDateString());

        return collect(range(0, EmployerMetrics::TREND_WEEKS - 1))->map(function (int $i) use ($trendStart, $byWeek) {
            $start = $trendStart->copy()->addWeeks($i);
            $week = $byWeek->get($start->toDateString(), collect());

            return ['start' => $start, 'visits' => $week->count(), 'cents' => (int) $week->sum('amount_cents')];
        })->all();
    }
}
