<?php

namespace Tests\Feature;

use App\Enums\DenialReason;
use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\BidImpactMetrics;
use App\Services\CreditLedger;
use App\Services\EmployerMetrics;
use App\Services\RedemptionReviewService;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The BID impact numbers come from the ledger alone and agree with every
 * employer/HR dashboard (ADR 0017).
 */
class BidImpactMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-15 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
    }

    private function metrics(): array
    {
        return app(BidImpactMetrics::class)->for();
    }

    private function redeem(Employee $employee, string $merchant, int $cents, string $decision): Redemption
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict($decision === 'hold' ? 91 : 5, $decision));

        return app(RedemptionService::class)->redeem($employee, Merchant::where('name', $merchant)->firstOrFail(), $cents);
    }

    /** A second, hand-built employer, so the rollup adds up more than one HR dashboard. */
    private function secondEmployer(): Employer
    {
        $employer = Employer::create(['bid_id' => Employer::orderBy('id')->firstOrFail()->bid_id, 'name' => 'Second Test Employer', 'monthly_allowance_cents' => 10000, 'staff_count' => 10]);
        $ledger = app(CreditLedger::class);
        $ledger->purchase($employer, 50000, 'TEST-1');
        foreach (['Test One', 'Test Two'] as $name) {
            $ledger->allocate($employer, Employee::create(['employer_id' => $employer->id, 'display_name' => $name]), 10000);
        }

        return $employer;
    }

    public function test_all_time_totals_equal_the_ledger(): void
    {
        $this->secondEmployer();
        $this->redeem(Employee::where('display_name', 'Test One')->sole(), 'Farragut Grind', 1250, 'clear');
        $this->redeem(Employee::orderBy('id')->firstOrFail(), 'Press Room Deli', 2000, 'hold');

        $earned = CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED);
        $all = $this->metrics()['all_time'];

        $this->assertSame((int) (clone $earned)->sum('amount_cents'), $all['spent_cents']);
        $this->assertSame((clone $earned)->count(), $all['visits']);
        $this->assertSame((clone $earned)->distinct()->count('merchant_id'), $all['merchants']);
        $this->assertSame(
            DB::table('credit_ledger_entries')->join('redemptions', 'redemptions.id', '=', 'credit_ledger_entries.redemption_id')
                ->where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)->distinct()->count('redemptions.employee_id'),
            $all['employees'],
        );
        $this->assertSame(2, $all['employers']);
        $this->assertSame($all, app(BidImpactMetrics::class)->totals(), 'The landing page reads the same totals');
    }

    public function test_this_month_and_the_trend_are_the_sum_of_every_employer_dashboard(): void
    {
        $second = $this->secondEmployer();
        $this->redeem(Employee::where('display_name', 'Test One')->sole(), 'Farragut Grind', 1250, 'clear');
        $this->redeem(Employee::where('display_name', 'Test Two')->sole(), 'Page & Quill Books', 3000, 'clear');

        $m = $this->metrics();
        $hr = Employer::orderBy('id')->get()->map(fn (Employer $e) => app(EmployerMetrics::class)->for($e));

        $this->assertCount(2, $m['employers']);
        $this->assertSame($second->id, $m['employers'][1]['employer']->id);
        $this->assertSame($hr->sum('spent_cents'), $m['this_month']['spent_cents']);
        $this->assertSame($hr->sum('visits'), $m['this_month']['visits']);
        $this->assertSame($hr->sum('redeemers'), $m['this_month']['employees']);
        $this->assertSame($hr->sum('held_cents'), $m['held_cents']);
        foreach ($m['weeks'] as $i => $week) {
            $this->assertSame($hr->sum(fn ($e) => $e['weeks'][$i]['cents']), $week['cents'], 'Week of '.$week['start']->toDateString());
            $this->assertSame($hr->sum(fn ($e) => $e['weeks'][$i]['visits']), $week['visits']);
        }
        $this->assertEquals($hr->all(), array_column($m['employers'], 'metrics'), 'The rollup is each HR dashboard, unchanged');
    }

    public function test_held_and_denied_redemptions_never_count(): void
    {
        $before = $this->metrics();
        $jordan = Employee::orderBy('id')->firstOrFail();
        $this->redeem($jordan, 'Press Room Deli', 2000, 'hold');
        $denied = $this->redeem($jordan, 'Press Room Deli', 3000, 'hold');
        app(RedemptionReviewService::class)->deny($denied, User::factory()->role(Role::BidAdmin)->create(), 'Test denial.', DenialReason::SuspectedAbuse);

        $after = $this->metrics();

        $this->assertSame($before['all_time'], $after['all_time']);
        $this->assertSame($before['this_month'], $after['this_month']);
        $this->assertSame([$before['held_count'] + 1, $before['held_cents'] + 2000], [$after['held_count'], $after['held_cents']]);
    }

    public function test_a_held_redemption_cleared_later_counts_in_the_week_it_happened(): void
    {
        $held = $this->redeem(Employee::orderBy('id')->firstOrFail(), 'Press Room Deli', 2000, 'hold');
        $before = $this->metrics()['weeks'];

        $this->travel(8)->days();
        app(RedemptionReviewService::class)->approve($held, User::factory()->role(Role::BidAdmin)->create(), 'Receipt checked.');
        $weeks = $this->metrics()['weeks'];

        // A week later the window has moved on by one: last week is index 6 now.
        $this->assertSame($before[7]['cents'] + 2000, $weeks[6]['cents']);
        $this->assertSame($before[7]['visits'] + 1, $weeks[6]['visits']);
        $this->assertSame(
            app(EmployerMetrics::class)->for(Employer::orderBy('id')->firstOrFail())['weeks'][6]['cents'],
            $weeks[6]['cents'],
        );
    }

    public function test_the_recirculation_estimate_is_the_outside_rate_times_the_ledger_total(): void
    {
        $m = $this->metrics();

        $this->assertSame((int) round($m['all_time']['spent_cents'] * 0.523), $m['recirculation_cents']);
    }

    public function test_an_empty_ledger_gives_zeros_and_no_estimate(): void
    {
        foreach (['credit_ledger_entries', 'redemption_decisions', 'merchant_payouts', 'credit_allocations', 'credit_purchases', 'redemptions'] as $table) {
            DB::table($table)->delete();
        }

        $m = $this->metrics();

        $this->assertFalse($m['has_activity']);
        $this->assertSame(['spent_cents' => 0, 'visits' => 0, 'merchants' => 0, 'employees' => 0, 'employers' => 0], $m['all_time']);
        $this->assertSame($m['all_time'], $m['this_month']);
        $this->assertSame(0, array_sum(array_column($m['weeks'], 'cents')));
        $this->assertNull($m['recirculation_cents']);
    }
}
