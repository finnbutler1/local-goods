<?php

namespace App\Support\Projections;

/**
 * One scenario and case of the business model, month by month (ADR 0030),
 * with the 4-year rollups the pages, CSV and docs show. Every number here
 * is a projection, not a result. Customer funds are reported on their own
 * and never added to revenue or cash.
 */
final class Projection
{
    /** Lines added up over a year; everything else is read at the year's end. */
    private const FLOWS = [
        'new_bids', 'new_employers', 'credits_bought', 'spend', 'visits', 'unspent', 'returned_to_employers',
        'bid_fees', 'platform_fees', 'merchant_fees', 'interchange',
        'stripe_funding', 'stripe_connect', 'ai_checks', 'hosting', 'bid_share',
        'support', 'sales_marketing', 'g_and_a', 'payroll',
        'revenue', 'cost_of_revenue', 'gross_profit', 'opex', 'ebitda',
    ];

    /**
     * @param  list<array<string, float|int>>  $months  month 1 first, up to the break-even search horizon
     * @param  array{independent_rate: float, chain_rate: float}|null  $recirculation
     */
    public function __construct(
        public readonly string $scenario,
        public readonly Assumptions $assumptions,
        private readonly array $months,
        private readonly ?array $recirculation = null,
    ) {}

    public function case(): string
    {
        return $this->assumptions->case;
    }

    /** @return list<array<string, float|int>> the months in the 4-year horizon */
    public function months(): array
    {
        return array_slice($this->months, 0, $this->assumptions->horizonMonths());
    }

    /** @return list<array<string, float|int>> every month calculated, including those after the horizon */
    public function allMonths(): array
    {
        return $this->months;
    }

    /** @return list<array<string, float|int|null>> one row per year: flows summed, counts at the year's end */
    public function years(): array
    {
        $years = [];

        foreach (array_chunk($this->months(), 12) as $i => $months) {
            $end = end($months);
            $year = ['year' => $i + 1] + $end;
            foreach (self::FLOWS as $line) {
                $year[$line] = array_sum(array_column($months, $line));
            }
            $year['employee_months'] = array_sum(array_column($months, 'employees'));
            $year['bid_months'] = array_sum(array_column($months, 'bids'));
            $year['merchant_months'] = array_sum(array_column($months, 'merchants'));
            $year['gross_margin'] = $year['revenue'] > 0 ? $year['gross_profit'] / $year['revenue'] : null;
            $years[] = $year;
        }

        return $years;
    }

    /** The first month from which monthly EBITDA stays above 0 through the search horizon, or null. */
    public function breakEvenMonth(): ?int
    {
        $from = null;
        foreach ($this->months as $month) {
            if ($month['ebitda'] > 0) {
                $from ??= $month['month'];
            } else {
                $from = null;
            }
        }

        return $from;
    }

    /** @return array{amount: float, month: int, still_growing: bool} the deepest cumulative EBITDA, in the whole search horizon */
    public function peakCashNeeded(): array
    {
        $cumulative = 0.0;
        $peak = ['amount' => 0.0, 'month' => 0];

        foreach ($this->months as $month) {
            $cumulative += $month['ebitda'];
            if (-$cumulative > $peak['amount']) {
                $peak = ['amount' => -$cumulative, 'month' => $month['month']];
            }
        }

        return $peak + ['still_growing' => $peak['month'] === count($this->months)];
    }

    /** @return list<float> cumulative EBITDA at each month's end in the horizon */
    public function cumulativeCash(): array
    {
        $running = 0.0;

        return array_map(function (array $month) use (&$running) {
            return $running += $month['ebitda'];
        }, $this->months());
    }

    /** @return array<string, float|null> year 4, per participating employee per month and per BID per year */
    public function unitEconomics(): array
    {
        $year = $this->years()[count($this->years()) - 1];
        $employeeMonths = $year['employee_months'];
        $bids = $year['bid_months'] / 12;

        return [
            'revenue_per_employee_month' => $employeeMonths > 0 ? $year['revenue'] / $employeeMonths : null,
            'cost_of_revenue_per_employee_month' => $employeeMonths > 0 ? $year['cost_of_revenue'] / $employeeMonths : null,
            'contribution_per_employee_month' => $employeeMonths > 0 ? $year['gross_profit'] / $employeeMonths : null,
            'revenue_per_bid_year' => $bids > 0 ? $year['revenue'] / $bids : null,
            'gross_profit_per_bid_year' => $bids > 0 ? $year['gross_profit'] / $bids : null,
        ];
    }

    /**
     * What the program would mean for the district, per year: dollars spent
     * at independent merchants, visits, the average per merchant, and the
     * outside study's rates applied to that spending (a labelled estimate).
     *
     * @return list<array<string, float|int|null>>
     */
    public function districtImpact(): array
    {
        return array_map(fn (array $year) => [
            'year' => $year['year'],
            'bids' => $year['bids'],
            'employers' => $year['employers'],
            'employees' => $year['employees'],
            'merchants' => $year['merchants'],
            'spend' => $year['spend'],
            'visits' => $year['visits'],
            'per_merchant' => $year['merchant_months'] > 0 ? $year['spend'] / ($year['merchant_months'] / 12) : null,
            'recirculated_if_independent' => $this->recirculation ? $year['spend'] * $this->recirculation['independent_rate'] : null,
            'recirculated_if_chain' => $this->recirculation ? $year['spend'] * $this->recirculation['chain_rate'] : null,
        ], $this->years());
    }

    /** @return list<array<string, float|int>> customer money at each year's end, plus what went back to employers that year */
    public function customerFunds(): array
    {
        return array_map(fn (array $year) => [
            'year' => $year['year'],
            'employee_balances' => $year['employee_balances'],
            'owed_to_merchants' => $year['owed_to_merchants'],
            'customer_funds' => $year['customer_funds'],
            'returned_to_employers' => $year['returned_to_employers'],
        ], $this->years());
    }
}
