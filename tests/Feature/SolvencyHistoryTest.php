<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\MerchantPayout;
use App\Models\SolvencyCheckRun;
use App\Services\Solvency\SolvencyCheck;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\TestCase;

/**
 * The historical check (ADR 0033) replays the ledger moment by moment, so a
 * violation in the middle of history is caught even when a later line
 * makes the totals at the end look right. Violations here are planted
 * with raw inserts, the way a bug that bypassed the services would look.
 */
class SolvencyHistoryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOnAFixedDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_violation_planted_mid_history_and_hidden_later_is_still_caught(): void
    {
        $employee = Employee::orderBy('id')->skip(5)->firstOrFail();
        $employer = Employer::firstOrFail();

        // An allowance with no allocation behind it on 1 Oct, "corrected" by a
        // stray allocation_out on 10 Oct: the totals at the end match again.
        $this->plant(['employee_id' => $employee->id, 'amount_cents' => 2500, 'type' => CreditLedgerEntry::TYPE_ALLOWANCE, 'created_at' => '2026-10-01 14:00:00']);
        $this->plant(['employer_id' => $employer->id, 'amount_cents' => -2500, 'type' => CreditLedgerEntry::TYPE_ALLOCATION_OUT, 'created_at' => '2026-10-10 14:00:00']);

        $check = app(SolvencyCheck::class);
        $this->assertTrue($check->now()->passes(), 'At the end it looks fine.');

        $history = $check->history();
        $this->assertFalse($history->passed());
        $this->assertCount(1, $history->windows);
        $this->assertSame('2026-10-01 14:00:00', $history->windows[0]['from']);
        $this->assertSame('2026-10-10 14:00:00', $history->windows[0]['to']);
        $this->assertSame(2500, $history->windows[0]['worst_cents']);
        $this->assertStringStartsWith('short by $25.00', $history->windows[0]['problem']);

        $this->artisan('ledger:check-solvency')
            ->expectsOutputToContain('First failed at 2026-10-01 14:00:00 UTC: short by $25.00')
            ->expectsOutputToContain('Failed from 2026-10-01 14:00:00 to 2026-10-10 14:00:00 UTC')
            ->assertFailed();

        $run = SolvencyCheckRun::latest();
        $this->assertFalse($run->passed);
        $this->assertSame('2026-10-01 14:00:00', $run->first_failure_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $run->failures['window_count']);
    }

    public function test_a_merchant_below_zero_is_never_offset_by_other_promises(): void
    {
        $payout = MerchantPayout::where('status', MerchantPayout::STATUS_SIMULATED)->orderByDesc('id')->firstOrFail();

        // A refund that takes the merchant $10 below zero and hands the credits back to an employee: net totals match.
        $refund = 1000 + $payout->merchant->payableCents();
        $this->plant(['merchant_id' => $payout->merchant_id, 'amount_cents' => -$refund, 'type' => CreditLedgerEntry::TYPE_MERCHANT_REFUNDED, 'created_at' => now()->utc()]);
        $this->plant(['employee_id' => Employee::orderBy('id')->firstOrFail()->id, 'amount_cents' => $refund, 'type' => CreditLedgerEntry::TYPE_REFUND, 'created_at' => now()->utc()]);

        $now = app(SolvencyCheck::class)->now();
        $this->assertFalse($now->isSolvent());
        $this->assertSame(1000, $now->shortfallCents());
        $this->assertSame(['merchant:'.$payout->merchant_id], $now->negativeAccounts);
        $this->assertStringContainsString(SolvencyCheck::accountLabel('merchant:'.$payout->merchant_id), $this->runCommand());
    }

    public function test_a_promise_missing_from_the_books_is_not_balanced(): void
    {
        $this->plant(['employer_id' => Employer::firstOrFail()->id, 'amount_cents' => -700, 'type' => CreditLedgerEntry::TYPE_ALLOCATION_OUT, 'created_at' => now()->utc()]);

        $history = app(SolvencyCheck::class)->history();
        $this->assertFalse($history->passed());
        $this->assertTrue($history->final->isSolvent(), 'More money than promises is not insolvent');
        $this->assertSame(700, $history->final->surplusCents());
        $this->assertStringContainsString('$7.00 in hand is not matched by any promise', $history->summary());
    }

    public function test_a_payout_record_with_no_ledger_line_shows_as_a_mismatch(): void
    {
        DB::table('merchant_payouts')->insert(['merchant_id' => MerchantPayout::firstOrFail()->merchant_id, 'amount_cents' => 1234,
            'status' => MerchantPayout::STATUS_SIMULATED, 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $history = app(SolvencyCheck::class)->history();
        $this->assertFalse($history->passed());
        $this->assertSame([], $history->windows, 'The ledger alone still adds up');
        $this->assertStringContainsString('Payouts sent or simulated come to', $history->mismatches[0]);
    }

    public function test_a_clean_history_passes_and_is_stored(): void
    {
        $this->artisan('ledger:check-solvency', ['--trigger' => 'deploy'])
            ->expectsOutputToContain('Pass: '.number_format(CreditLedgerEntry::count()).' ledger entries')
            ->assertSuccessful();

        $run = SolvencyCheckRun::latest();
        $this->assertTrue($run->passed);
        $this->assertSame(SolvencyCheckRun::TRIGGER_DEPLOY, $run->trigger);
        $this->assertSame(CreditLedgerEntry::count(), $run->entries_checked);
        $this->assertSame(CreditLedgerEntry::min('created_at'), $run->first_entry_at->format('Y-m-d H:i:s'));
        $this->assertNull($run->failures);

        $this->artisan('ledger:check-solvency', ['--no-record' => true])->assertSuccessful();
        $this->assertSame(1, SolvencyCheckRun::count());
        $this->artisan('ledger:check-solvency', ['--trigger' => 'admin'])->assertExitCode(2);
    }

    /** A raw insert, as a bug that bypassed the services would write it. */
    private function plant(array $line): void
    {
        DB::table('credit_ledger_entries')->insert($line + ['description' => 'Planted by a test']);
    }

    private function runCommand(): string
    {
        Artisan::call('ledger:check-solvency', ['--no-record' => true]);

        return Artisan::output();
    }
}
