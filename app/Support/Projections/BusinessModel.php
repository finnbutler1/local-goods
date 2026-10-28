<?php

namespace App\Support\Projections;

use InvalidArgumentException;

/**
 * The business model (ADR 0030): a pure monthly calculator over the
 * assumptions in config/projections.php. No database, no network, and it
 * never reads the live fee settings, the ledger or Stripe. Dollars are
 * floats because this is projection arithmetic, never money handling.
 *
 * Each BID launches in a month set by its scenario, then enrolls employers
 * and merchants in a straight line. Revenue is only the four fee lines.
 * Customer money (credits bought, unspent balances, payouts owed) is
 * tracked on the side and is never revenue or operating cash; unspent
 * credits go back to the employer, so breakage is excluded by policy.
 */
final class BusinessModel
{
    public const REVENUE_LINES = [
        'bid_fees' => 'BID initiation fees',
        'platform_fees' => 'Employer platform fees',
        'merchant_fees' => 'Merchant fees',
        'interchange' => 'Interchange (card-issuing phase, off)',
    ];

    public const COST_OF_REVENUE_LINES = [
        'stripe_funding' => 'Stripe: employer purchases (ACH and card)',
        'stripe_connect' => 'Stripe Connect: merchant accounts and payouts',
        'ai_checks' => 'AI fraud checks (Claude API)',
        'hosting' => 'Hosting (AWS)',
        'bid_share' => 'BID partner revenue share',
    ];

    public const OPEX_LINES = [
        'support' => 'Customer support',
        'sales_marketing' => 'Sales and marketing',
        'g_and_a' => 'G&A, legal and compliance',
        'payroll' => 'Payroll',
    ];

    /** The participating employees a single BID is searched up to before "not reachable". */
    private const MAX_EMPLOYEES = 250_000;

    /** The highest employer fee tried when looking for the break-even fee. */
    private const MAX_FEE = 100.0;

    /**
     * @param  array<string, mixed>  $config  the projections config
     * @param  array{independent_rate: float, chain_rate: float}|null  $recirculation  the outside study's rates (config/impact.php)
     */
    public function __construct(private readonly array $config, private readonly ?array $recirculation = null) {}

    public static function fromConfig(): self
    {
        return new self(config('projections'), config('impact.recirculation'));
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    /** The same model with these assumptions set to one value in every case. @param array<string, float|int> $values */
    public function with(array $values): self
    {
        $config = $this->config;
        foreach ($values as $key => $value) {
            if (! isset($config['assumptions'][$key])) {
                throw new InvalidArgumentException("Unknown assumption \"{$key}\".");
            }
            foreach (Assumptions::CASES as $case) {
                $config['assumptions'][$key][$case] = $value;
            }
        }

        return new self($config, $this->recirculation);
    }

    public function assumptions(string $case = 'base'): Assumptions
    {
        return new Assumptions($this->config, $case);
    }

    public function run(string $scenario, string $case = 'base'): Projection
    {
        $a = $this->assumptions($case);
        $launches = $this->launchMonths($scenario);
        $months = [];
        $previousEmployers = 0.0;

        for ($m = 1; $m <= $a->searchMonths(); $m++) {
            $employers = $merchants = 0.0;
            $bids = 0;
            foreach ($launches as $launched) {
                if ($launched <= $m) {
                    $age = $m - $launched + 1;
                    $employers += $a->value('employers_per_bid') * min(1, $age / $a->value('employer_ramp_months'));
                    $merchants += $a->value('merchants_per_bid') * min(1, $age / $a->value('merchant_ramp_months'));
                    $bids++;
                }
            }
            $newBids = count(array_keys($launches, $m, true));

            $months[] = ['month' => $m] + $this->lines(
                $a,
                employees: $employers * $a->value('employees_per_employer'),
                merchants: $merchants,
                bids: $bids,
                newBids: $newBids,
                newEmployers: max(0, $employers - $previousEmployers),
                legalSetup: $m === 1,
                interchange: $a->cardIssuingFrom() !== null && $m >= $a->cardIssuingFrom(),
            );
            $previousEmployers = $employers;
        }

        return new Projection($scenario, $a, $this->withCustomerFunds($a, $months), $this->recirculation);
    }

    /**
     * The month each BID launches: the first in month 1, then each year's new
     * BIDs spread evenly across that year, the last in its final month.
     *
     * @return list<int>
     */
    public function launchMonths(string $scenario): array
    {
        $launches = [1];
        $previous = 1;

        foreach ($this->assumptions()->bidsByYearEnd($scenario) as $year => $target) {
            $added = $target - $previous;
            for ($k = 1; $k <= $added; $k++) {
                $launches[] = 12 * $year + (int) ceil($k / $added * 12);
            }
            $previous = max($previous, $target);
        }

        return $launches;
    }

    /**
     * One fully enrolled BID in a quiet month: no launches, no new employers,
     * no one-time legal setup. What "What it takes" is worked out on.
     *
     * @return array<string, float|int>
     */
    public function fullyRampedBid(string $case, float $employees, ?float $employerFee = null): array
    {
        $a = $this->assumptions($case);

        return $this->lines($a, $employees, $a->value('merchants_per_bid'), 1, 0, 0.0, false, $a->cardIssuingFrom() !== null, $employerFee);
    }

    /**
     * Scenario A at full ramp, with the case's fees: the fewest participating
     * employees (and so employers) for one BID's monthly EBITDA to reach 0,
     * and the employer fee that gets there at the case's own participation.
     * Searched, not solved, because payroll rises in steps.
     */
    public function whatItTakes(string $case = 'base'): WhatItTakes
    {
        $a = $this->assumptions($case);
        $perEmployer = $a->value('employees_per_employer');
        $participation = $a->value('employers_per_bid') * $perEmployer;

        $employees = null;
        for ($n = 1; $n <= self::MAX_EMPLOYEES; $n++) {
            if ($this->fullyRampedBid($case, $n)['ebitda'] >= 0) {
                $employees = $n;
                break;
            }
        }

        $fee = null;
        if ($this->fullyRampedBid($case, $participation, self::MAX_FEE)['ebitda'] >= 0) {
            [$low, $high] = [0.0, self::MAX_FEE];
            for ($i = 0; $i < 60; $i++) {
                $middle = ($low + $high) / 2;
                $this->fullyRampedBid($case, $participation, $middle)['ebitda'] >= 0 ? $high = $middle : $low = $middle;
            }
            // The smallest whole cent that breaks even.
            $fee = ceil(round($high * 100, 6)) / 100;
            if ($this->fullyRampedBid($case, $participation, $fee)['ebitda'] < 0) {
                $fee += 0.01;
            }
        }

        return new WhatItTakes(
            case: $case,
            employees: $employees,
            employers: $employees === null ? null : (int) ceil($employees / $perEmployer),
            employeesPerEmployer: $perEmployer,
            currentFee: $a->value('employer_platform_fee'),
            participation: $participation,
            breakEvenFee: $fee === null ? null : round($fee, 2),
        );
    }

    /**
     * One month's lines. Revenue is the four fee lines only; the credits
     * employers buy, and what employees leave unspent, are customer money.
     *
     * @return array<string, float|int>
     */
    private function lines(Assumptions $a, float $employees, float $merchants, int $bids, int $newBids, float $newEmployers, bool $legalSetup, bool $interchange, ?float $employerFee = null): array
    {
        $fee = $employerFee ?? $a->value('employer_platform_fee');
        $perEmployer = $a->value('employees_per_employer');
        $employers = $employees / $perEmployer;

        $credits = $employees * $a->value('monthly_credit_per_employee');
        $spend = $credits * $a->value('utilization');
        $visits = $spend / $a->value('average_ticket');

        $revenue = [
            'bid_fees' => $newBids * $a->value('bid_initiation_fee'),
            'platform_fees' => $employees * $fee,
            'merchant_fees' => $spend * $a->value('merchant_fee_rate'),
            'interchange' => $interchange ? $spend * $a->value('interchange_rate') : 0.0,
        ];

        // Each employer buys a month of credits plus the platform fee in one purchase.
        $purchase = $perEmployer * ($a->value('monthly_credit_per_employee') + $fee);
        $ach = min($a->value('stripe_ach_rate') * $purchase, $a->value('stripe_ach_cap'));
        $card = $a->value('stripe_card_rate') * $purchase + $a->value('stripe_card_fixed');

        $costOfRevenue = [
            'stripe_funding' => $employers * ($a->value('ach_share') * $ach + (1 - $a->value('ach_share')) * $card),
            'stripe_connect' => $merchants * ($a->value('connect_active_account') + $a->value('payouts_per_merchant_per_month') * $a->value('connect_payout_fixed'))
                + $a->value('connect_payout_rate') * ($spend - $revenue['merchant_fees']),
            'ai_checks' => $visits * $a->aiCostPerCheck(),
            'hosting' => $a->value('hosting_monthly') + $a->value('hosting_per_bid_monthly') * $bids,
            'bid_share' => $a->value('bid_revenue_share') * $revenue['platform_fees'],
        ];

        $engineers = $employees < $a->value('first_engineer_at_employees')
            ? 0 : 1 + (int) floor(($employees - $a->value('first_engineer_at_employees')) / $a->value('employees_per_extra_engineer'));
        $accountManagers = (int) ceil(($bids - 1) / $a->value('account_manager_bids_per_manager'));
        $ops = (int) floor($employees / $a->value('ops_employees_per_person')) + ($bids >= $a->value('compliance_hire_at_bids') ? 1 : 0);

        $opex = [
            'support' => $a->value('support_per_employee') * $employees,
            'sales_marketing' => $a->value('marketing_monthly') + $a->value('bid_launch_cost') * $newBids + $a->value('sales_cost_per_new_employer') * $newEmployers,
            'g_and_a' => $a->value('ga_monthly') + $a->value('ga_per_bid_monthly') * $bids + ($legalSetup ? $a->value('legal_setup') : 0),
            'payroll' => ($a->value('founder_salary') + $engineers * $a->value('engineer_salary')
                + $accountManagers * $a->value('account_manager_salary') + $ops * $a->value('ops_salary')) / 12,
        ];

        $totalRevenue = array_sum($revenue);
        $totalCostOfRevenue = array_sum($costOfRevenue);

        return [
            'bids' => $bids,
            'new_bids' => $newBids,
            'employers' => $employers,
            'new_employers' => $newEmployers,
            'employees' => $employees,
            'merchants' => $merchants,
            'credits_bought' => $credits,
            'spend' => $spend,
            'visits' => $visits,
            'unspent' => $credits - $spend,
            'headcount' => 1 + $engineers + $accountManagers + $ops,
        ] + $revenue + $costOfRevenue + $opex + [
            'revenue' => $totalRevenue,
            'cost_of_revenue' => $totalCostOfRevenue,
            'gross_profit' => $totalRevenue - $totalCostOfRevenue,
            'opex' => array_sum($opex),
            'ebitda' => $totalRevenue - $totalCostOfRevenue - array_sum($opex),
        ];
    }

    /**
     * Customer money at each month's end, never revenue: credits employees
     * haven't spent yet (going back to the employer after a few months) and
     * cleared sales waiting for their weekly payout.
     *
     * @param  list<array<string, float|int>>  $months
     * @return list<array<string, float|int>>
     */
    private function withCustomerFunds(Assumptions $a, array $months): array
    {
        $hold = (int) $a->value('unspent_return_months');

        foreach ($months as $i => $month) {
            $balances = 0.0;
            for ($j = max(0, $i - $hold + 1); $j <= $i; $j++) {
                $balances += $months[$j]['unspent'];
            }
            $months[$i]['employee_balances'] = $balances;
            $months[$i]['returned_to_employers'] = $i >= $hold ? $months[$i - $hold]['unspent'] : 0.0;
            $months[$i]['owed_to_merchants'] = $month['spend'] * $a->value('payout_lag_days') / 30;
            $months[$i]['customer_funds'] = $balances + $months[$i]['owed_to_merchants'];
        }

        return $months;
    }
}
