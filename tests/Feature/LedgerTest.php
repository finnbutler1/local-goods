<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_balance_is_the_sum_of_ledger_entries(): void
    {
        // Oct 20: the six-week window covers September and October allowances only.
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(DatabaseSeeder::class);

        $employee = Employee::firstOrFail();

        $this->assertSame(2 * 15000 - 18000, $employee->balanceCents());
    }

    public function test_seeded_balance_is_always_a_round_number(): void
    {
        // Oct 10: the window reaches back into August, so three allowances.
        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->seed(DatabaseSeeder::class);

        $balance = Employee::firstOrFail()->balanceCents();

        $this->assertSame(3 * 15000 - 18000, $balance);
        $this->assertSame(0, $balance % 1000);
    }

    public function test_ledger_entries_cannot_be_updated(): void
    {
        $this->seed(DatabaseSeeder::class);
        $entry = CreditLedgerEntry::firstOrFail();

        $this->expectException(LogicException::class);
        $entry->update(['amount_cents' => 999999]);
    }

    public function test_ledger_entries_cannot_be_deleted(): void
    {
        $this->seed(DatabaseSeeder::class);
        $entry = CreditLedgerEntry::firstOrFail();

        $this->expectException(LogicException::class);
        $entry->delete();
    }
}
