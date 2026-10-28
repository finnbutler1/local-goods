<?php

namespace Tests\Unit;

use App\Support\Projections\BusinessModel;
use PHPUnit\Framework\TestCase;

/**
 * The business model's arithmetic (ADR 0030). Hand-checked small cases,
 * plus the rules that keep it honest: revenue is only the fee lines,
 * customer money and unspent credits never count, and bigger scenarios and
 * better cases never shrink the program.
 */
class BusinessModelTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function config(): array
    {
        return require __DIR__.'/../../config/projections.php';
    }

    private static function real(): BusinessModel
    {
        return new BusinessModel(self::config(), ['independent_rate' => 0.523, 'chain_rate' => 0.158]);
    }

    /**
     * One BID, one employer of 100 people from month 1, round numbers and
     * no payroll steps, so every line can be worked out on paper.
     */
    private static function tiny(): BusinessModel
    {
        $config = self::config();
        $config['scenarios'] = ['t' => ['bids_by_year_end' => [1, 1, 1, 1]] + $config['scenarios']['a']];

        return (new BusinessModel($config))->with([
            'employers_per_bid' => 1, 'employer_ramp_months' => 1, 'employees_per_employer' => 100,
            'monthly_credit_per_employee' => 50, 'utilization' => 0.8, 'average_ticket' => 10,
            'merchants_per_bid' => 2, 'merchant_ramp_months' => 1,
            'bid_initiation_fee' => 1000, 'employer_platform_fee' => 5, 'merchant_fee_rate' => 0.01,
            'ach_share' => 1, 'ai_input_tokens_per_check' => 1000, 'ai_output_tokens_per_check' => 500,
            'hosting_monthly' => 100, 'hosting_per_bid_monthly' => 0, 'bid_revenue_share' => 0.10,
            'payouts_per_merchant_per_month' => 4, 'support_per_employee' => 0.5,
            'marketing_monthly' => 0, 'bid_launch_cost' => 0, 'sales_cost_per_new_employer' => 0,
            'ga_monthly' => 1000, 'ga_per_bid_monthly' => 0, 'legal_setup' => 0, 'founder_salary' => 12000,
            'first_engineer_at_employees' => 1_000_000, 'ops_employees_per_person' => 1_000_000, 'compliance_hire_at_bids' => 1000,
        ]);
    }

    public function test_a_hand_checked_month(): void
    {
        [$first, $second] = self::tiny()->run('t')->months();

        // 100 employees × $50 = $5,000 of credits; 80% spent = $4,000; at $10 a visit, 400 visits.
        $this->assertEqualsWithDelta(5000, $first['credits_bought'], 1e-9);
        $this->assertEqualsWithDelta(4000, $first['spend'], 1e-9);
        $this->assertEqualsWithDelta(400, $first['visits'], 1e-9);

        // Revenue: $1,000 BID fee + 100 × $5 + 1% of $4,000.
        $this->assertEqualsWithDelta(1000, $first['bid_fees'], 1e-9);
        $this->assertEqualsWithDelta(500, $first['platform_fees'], 1e-9);
        $this->assertEqualsWithDelta(40, $first['merchant_fees'], 1e-9);
        $this->assertEqualsWithDelta(1540, $first['revenue'], 1e-9);

        // Stripe: one $5,500 ACH purchase, 0.8% capped at $5. Connect: 2 × ($2 + 4 × 25¢) + 0.25% of ($4,000 − $40).
        $this->assertEqualsWithDelta(5, $first['stripe_funding'], 1e-9);
        $this->assertEqualsWithDelta(6 + 9.90, $first['stripe_connect'], 1e-9);
        // AI: 1,000 tokens × $4/M + 500 × $20/M = 1.4¢ a check, × 400.
        $this->assertEqualsWithDelta(5.60, $first['ai_checks'], 1e-9);
        $this->assertEqualsWithDelta(100, $first['hosting'], 1e-9);
        $this->assertEqualsWithDelta(50, $first['bid_share'], 1e-9);
        $this->assertEqualsWithDelta(176.50, $first['cost_of_revenue'], 1e-9);

        // Opex: 100 × 50¢ support + $1,000 G&A + $12,000 / 12 founder.
        $this->assertEqualsWithDelta(2050, $first['opex'], 1e-9);
        $this->assertEqualsWithDelta(1540 - 176.50 - 2050, $first['ebitda'], 1e-9);

        // The BID fee is charged once.
        $this->assertEqualsWithDelta(0, $second['bid_fees'], 1e-9);
        $this->assertEqualsWithDelta(540 - 176.50 - 2050, $second['ebitda'], 1e-9);
    }

    public function test_what_it_takes_is_hand_checked(): void
    {
        $model = self::tiny();
        $needs = $model->whatItTakes();

        // Each employee adds $5 + 40¢ − 5¢ ACH − 9.9¢ payout − 5.6¢ AI − 50¢ BID share − 50¢ support = $4.195.
        // Fixed: $6 Connect accounts + $100 hosting + $1,000 G&A + $1,000 founder = $2,106. 2,106 / 4.195 = 502.03.
        $this->assertSame(503, $needs->employees);
        $this->assertSame(6, $needs->employers);
        $this->assertGreaterThanOrEqual(0, $model->fullyRampedBid('base', 503)['ebitda']);
        $this->assertLessThan(0, $model->fullyRampedBid('base', 502)['ebitda']);

        // At 100 employees: 100f + $40 − $5 − $15.90 − $5.60 − $100 − 10f − $50 − $2,000 = 90f − $2,136.50, so f = $23.7389.
        $this->assertSame(23.74, $needs->breakEvenFee);
        $this->assertGreaterThanOrEqual(0, $model->fullyRampedBid('base', 100, 23.74)['ebitda']);
        $this->assertLessThan(0, $model->fullyRampedBid('base', 100, 23.73)['ebitda']);
    }

    public function test_what_it_takes_says_when_no_fee_is_enough(): void
    {
        $needs = self::tiny()->with(['employees_per_employer' => 1])->whatItTakes();

        $this->assertNull($needs->breakEvenFee);
        $this->assertNotNull($needs->employees);
    }

    public function test_what_it_takes_at_the_real_base_case(): void
    {
        $model = self::real();
        $needs = $model->whatItTakes();

        $this->assertGreaterThanOrEqual(0, $model->fullyRampedBid('base', $needs->employees)['ebitda']);
        $this->assertLessThan(0, $model->fullyRampedBid('base', $needs->employees - 1)['ebitda']);
        $this->assertSame((int) ceil($needs->employees / 60), $needs->employers);
        $this->assertGreaterThanOrEqual(0, $model->fullyRampedBid('base', 1200, $needs->breakEvenFee)['ebitda']);
        $this->assertLessThan(0, $model->fullyRampedBid('base', 1200, $needs->breakEvenFee - 0.01)['ebitda']);
    }

    public function test_revenue_is_the_sum_of_its_lines_and_each_lever_can_be_zero(): void
    {
        foreach (['a', 'b', 'c'] as $scenario) {
            foreach (self::real()->run($scenario)->allMonths() as $month) {
                $lines = array_sum(array_intersect_key($month, BusinessModel::REVENUE_LINES));
                $this->assertEqualsWithDelta($lines, $month['revenue'], 1e-6);
                $this->assertEqualsWithDelta($month['revenue'] - $month['cost_of_revenue'] - $month['opex'], $month['ebitda'], 1e-6);
            }
        }

        $levers = ['bid_initiation_fee' => 'bid_fees', 'employer_platform_fee' => 'platform_fees', 'merchant_fee_rate' => 'merchant_fees'];
        foreach ($levers as $assumption => $line) {
            $months = self::real()->with([$assumption => 0])->run('c')->allMonths();
            $this->assertSame(0.0, (float) array_sum(array_column($months, $line)), $line);
            $this->assertGreaterThan(0, array_sum(array_column(self::real()->run('c')->allMonths(), $line)), $line);
        }
        $this->assertSame(0.0, (float) array_sum(array_column(self::real()->run('c')->allMonths(), 'interchange')), 'Card issuing is off.');
    }

    public function test_interchange_counts_only_once_card_issuing_is_turned_on(): void
    {
        $config = self::config();
        $config['card_issuing'] = ['enabled' => true, 'start_month' => 3];
        $months = (new BusinessModel($config))->run('a')->months();

        $this->assertSame(0.0, $months[1]['interchange']);
        $this->assertEqualsWithDelta($months[2]['spend'] * 0.0135, $months[2]['interchange'], 1e-9);
    }

    public function test_customer_funds_are_never_revenue(): void
    {
        $base = self::real()->run('b');
        $longer = self::real()->with(['unspent_return_months' => 12, 'payout_lag_days' => 30])->run('b');

        $this->assertGreaterThan($base->customerFunds()[3]['customer_funds'], $longer->customerFunds()[3]['customer_funds']);
        foreach ($base->allMonths() as $i => $month) {
            $this->assertSame($month['revenue'], $longer->allMonths()[$i]['revenue']);
            $this->assertSame($month['ebitda'], $longer->allMonths()[$i]['ebitda']);
        }
    }

    public function test_customer_funds_are_hand_checked(): void
    {
        $months = self::tiny()->run('t')->months();

        // $1,000 left unspent each month, held 3 months, then back to the employer.
        $this->assertEqualsWithDelta(1000, $months[0]['employee_balances'], 1e-9);
        $this->assertEqualsWithDelta(3000, $months[2]['employee_balances'], 1e-9);
        $this->assertEqualsWithDelta(3000, $months[5]['employee_balances'], 1e-9);
        $this->assertEqualsWithDelta(0, $months[2]['returned_to_employers'], 1e-9);
        $this->assertEqualsWithDelta(1000, $months[3]['returned_to_employers'], 1e-9);
        $this->assertEqualsWithDelta(4000 * 7 / 30, $months[0]['owed_to_merchants'], 1e-9);
    }

    public function test_unspent_credits_are_never_revenue(): void
    {
        $noMerchantFee = self::real()->with(['merchant_fee_rate' => 0]);
        $all = $noMerchantFee->with(['utilization' => 1])->run('c')->allMonths();
        $half = $noMerchantFee->with(['utilization' => 0.5])->run('c')->allMonths();
        foreach ($all as $i => $month) {
            $this->assertSame($month['revenue'], $half[$i]['revenue'], 'Revenue must not depend on how much goes unspent.');
        }

        $spent = self::real()->with(['utilization' => 0.95])->run('c')->years();
        $lessSpent = self::real()->with(['utilization' => 0.5])->run('c')->years();
        foreach ($spent as $i => $year) {
            $this->assertGreaterThanOrEqual($lessSpent[$i]['revenue'], $year['revenue']);
        }
    }

    public function test_a_is_never_larger_than_b_and_b_never_larger_than_c(): void
    {
        foreach (['low', 'base', 'high'] as $case) {
            [$a, $b, $c] = array_map(fn ($s) => self::real()->run($s, $case), ['a', 'b', 'c']);

            foreach (['bids', 'employers', 'employees', 'merchants', 'spend', 'visits', 'platform_fees', 'merchant_fees'] as $line) {
                foreach ($a->allMonths() as $i => $month) {
                    $this->assertLessThanOrEqual($b->allMonths()[$i][$line] + 1e-6, $month[$line], "{$case} {$line} month ".($i + 1));
                    $this->assertLessThanOrEqual($c->allMonths()[$i][$line] + 1e-6, $b->allMonths()[$i][$line], "{$case} {$line} month ".($i + 1));
                }
            }
            // BID fees come in lumps in launch months, so total revenue is compared by year.
            foreach ($a->years() as $i => $year) {
                $this->assertLessThanOrEqual($b->years()[$i]['revenue'], $year['revenue']);
                $this->assertLessThanOrEqual($c->years()[$i]['revenue'], $b->years()[$i]['revenue']);
            }
        }
    }

    /**
     * Revenue, spending, visits and the program's size only go up from low to
     * high, because only demand and pricing change. EBITDA isn't checked: a
     * bigger program hires people in steps, so it can dip.
     */
    public function test_low_is_never_above_base_and_base_never_above_high(): void
    {
        foreach (['a', 'b', 'c'] as $scenario) {
            [$low, $base, $high] = array_map(fn ($case) => self::real()->run($scenario, $case)->years(), ['low', 'base', 'high']);

            foreach (['revenue', 'spend', 'visits', 'employees', 'employers', 'merchants', 'gross_profit'] as $line) {
                foreach ($base as $i => $year) {
                    $this->assertLessThanOrEqual($year[$line], $low[$i][$line], "{$scenario} {$line} year ".($i + 1));
                    $this->assertLessThanOrEqual($high[$i][$line], $year[$line], "{$scenario} {$line} year ".($i + 1));
                }
            }
        }
    }

    public function test_launch_months_spread_each_years_new_bids(): void
    {
        $model = self::real();

        $this->assertSame([1], $model->launchMonths('a'));
        $this->assertSame([1, 12, 15, 18, 21, 24, 27, 30, 33, 36, 42, 48], $model->launchMonths('b'));
        $this->assertCount(100, $model->launchMonths('c'));
        $this->assertSame(48, max($model->launchMonths('c')));
    }

    public function test_break_even_and_peak_cash(): void
    {
        $a = self::real()->run('a');
        $this->assertNull($a->breakEvenMonth());
        $this->assertTrue($a->peakCashNeeded()['still_growing']);

        $high = self::real()->run('a', 'high');
        $month = $high->breakEvenMonth();
        $this->assertNotNull($month);
        foreach ($high->allMonths() as $m) {
            if ($m['month'] >= $month) {
                $this->assertGreaterThan(0, $m['ebitda']);
            }
        }
        $this->assertLessThanOrEqual(0, $high->allMonths()[$month - 2]['ebitda']);

        $cash = $high->cumulativeCash();
        $this->assertEqualsWithDelta(-min($cash), $high->peakCashNeeded()['amount'], 1e-6);
    }

    public function test_the_model_never_reads_live_fees_the_ledger_or_stripe_and_nothing_live_reads_it(): void
    {
        $root = __DIR__.'/../../';
        foreach (glob($root.'app/Support/Projections/*.php') as $file) {
            $source = file_get_contents($file);
            preg_match_all('/^use (.+);$/m', $source, $imports);
            $this->assertSame([], array_diff($imports[1], ['InvalidArgumentException', 'RuntimeException']), basename($file).' imports only plain PHP.');
            $this->assertDoesNotMatchRegularExpression('/\\\\?App\\\\(?!Support\\\\Projections)|DB::|Http::|Cache::/', preg_replace('/^namespace .+$/m', '', $source), basename($file));
        }

        $live = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'app/Services'));
        foreach ($live as $file) {
            if ($file->isFile()) {
                $this->assertStringNotContainsString('Projections', file_get_contents($file->getPathname()), $file->getFilename());
            }
        }
    }
}
