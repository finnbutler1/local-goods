<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\DemoActivityAction;
use App\Models\DemoActivityDay;
use App\Models\Employee;
use App\Models\SolvencyCheckRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsDemoActivity;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * The demo activity generator (ADR 0032) checks the solvency rule
 * (ADR 0033) every tick: before acting, so it never builds on a ledger
 * that already breaks it, and after, stored as the page's "last run". A
 * failure stops the day; the tick still never throws.
 */
class DemoActivitySolvencyTest extends TestCase
{
    use RefreshDatabase;
    use RunsDemoActivity;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startDemoActivity();
    }

    public function test_a_normal_day_stores_a_passing_check_after_each_tick(): void
    {
        $this->runDay('2026-10-26');

        $this->assertNull(DemoActivityDay::firstOrFail()->halted_reason);
        $runs = SolvencyCheckRun::where('trigger', SolvencyCheckRun::TRIGGER_DEMO_ACTIVITY)->orderBy('id')->get();
        $this->assertCount(144, $runs, 'One per tick, every five minutes from 07:00 to 18:55');
        $this->assertTrue($runs->every->passed);
        $this->assertSame(CreditLedgerEntry::count(), $runs->last()->entries_checked);
    }

    public function test_a_failed_check_stops_the_day_and_nothing_more_is_done(): void
    {
        $this->tickAt('2026-10-26 07:00');
        $this->tickAt('2026-10-26 09:30');
        $done = DemoActivityAction::where('status', DemoActivityAction::STATUS_DONE)->count();
        $lines = CreditLedgerEntry::count();

        // An allowance with no allocation behind it, as a bug that bypassed the services would leave it.
        DB::table('credit_ledger_entries')->insert(['employee_id' => Employee::orderBy('id')->skip(8)->firstOrFail()->id, 'amount_cents' => 1800,
            'type' => CreditLedgerEntry::TYPE_ALLOWANCE, 'description' => 'Planted by a test', 'created_at' => now()->utc()]);

        $lines = $lines + 1;
        $log = $this->tickAt('2026-10-26 12:00');

        $day = DemoActivityDay::firstOrFail();
        $this->assertStringStartsWith('the solvency check failed from 2026-10-26 13:30:00 UTC: short by $18.00', $day->halted_reason);
        $this->assertStringEndsWith('See /where-the-money-is.', $day->halted_reason);
        $this->assertSame(['Stopped for today: '.$day->halted_reason], $log);
        $this->assertFalse(SolvencyCheckRun::latest()->passed);

        foreach (['13:00', '15:30', '18:55'] as $time) {
            $this->assertSame(['Stopped for today: '.$day->halted_reason], $this->tickAt('2026-10-26 '.$time));
        }
        $this->assertSame($done, DemoActivityAction::where('status', DemoActivityAction::STATUS_DONE)->count(), 'Nothing more done that day');
        $this->assertSame($lines, CreditLedgerEntry::count(), 'Not one ledger line more');
    }

    public function test_a_payout_record_that_disagrees_with_the_ledger_stops_the_day_at_once(): void
    {
        $this->tickAt('2026-10-26 07:00');
        DB::table('merchant_payouts')->where('status', 'simulated')->orderByDesc('id')->limit(1)->update(['amount_cents' => DB::raw('amount_cents + 100')]);

        $this->tickAt('2026-10-26 07:05');

        $this->assertStringStartsWith('the solvency check failed: Payouts sent or simulated come to', DemoActivityDay::firstOrFail()->halted_reason);
        $this->assertStringNotContainsString('..', DemoActivityDay::firstOrFail()->halted_reason);
    }

    public function test_with_the_cheap_tick_check_the_whole_history_is_still_replayed_once_a_day(): void
    {
        config(['solvency.tick_check' => 'current', 'solvency.history_check_daily_at' => '07:00']);

        $this->runDay('2026-10-26');

        $this->assertNull(DemoActivityDay::firstOrFail()->halted_reason);
        $this->assertSame(1, SolvencyCheckRun::where('trigger', SolvencyCheckRun::TRIGGER_DEMO_ACTIVITY)->count());
        $this->assertTrue(SolvencyCheckRun::latest()->passed);
    }
}
