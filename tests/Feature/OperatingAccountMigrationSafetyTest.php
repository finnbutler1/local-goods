<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\OperatingSweep;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Concerns\RollsBackMigrations;
use Tests\TestCase;

/**
 * The operating account migration (ADR 0034) runs on the live RDS database.
 * This rolls it back and runs it again on the seeded demo and checks no
 * money row, ledger entry or balance moved, and the seed is still solvent.
 * Runs on SQLite and in CI's MySQL job.
 */
class OperatingAccountMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;

    private const MIGRATION = 'database/migrations/2026_11_02_000001_add_operating_account.php';

    public function test_existing_money_rows_and_balances_are_unchanged(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();

        $this->rollBackSince(self::MIGRATION);
        $this->assertFalse(Schema::hasTable('operating_accounts'));
        $this->assertFalse(Schema::hasTable('operating_sweeps'));
        $this->assertFalse(Schema::hasColumn('credit_ledger_entries', 'operating_sweep_id'));

        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('operating_accounts')->count());
        $this->assertSame(0, DB::table('operating_sweeps')->count());
        $this->assertSame(0, DB::table('credit_ledger_entries')->whereNotNull('operating_sweep_id')->count());
        $this->artisan('ledger:check-solvency', ['--no-record' => true])->assertSuccessful();
    }

    public function test_a_sweep_keeps_its_decision_and_only_its_stripe_outcome_changes(): void
    {
        $sweep = OperatingSweep::create([
            'amount_cents' => 16, 'channel' => OperatingSweep::CHANNEL_STRIPE, 'status' => OperatingSweep::STATUS_PENDING,
            'open_key' => OperatingSweep::OPEN_KEY, 'reason' => 'Example reason', 'reviewed_against_cents' => 16,
        ]);

        $sweep->update(['status' => OperatingSweep::STATUS_SENT, 'open_key' => null, 'stripe_transfer_id' => 'tr_fake_example', 'swept_at' => now()]);
        $this->assertSame(OperatingSweep::STATUS_SENT, $sweep->refresh()->status);

        $this->expectException(LogicException::class);
        $sweep->update(['amount_cents' => 17]);
    }

    public function test_only_one_sweep_can_be_open(): void
    {
        $open = ['amount_cents' => 1, 'channel' => OperatingSweep::CHANNEL_STRIPE, 'status' => OperatingSweep::STATUS_PENDING,
            'open_key' => OperatingSweep::OPEN_KEY, 'reason' => 'Example reason', 'reviewed_against_cents' => 1];
        OperatingSweep::create($open);

        $this->expectException(UniqueConstraintViolationException::class);
        OperatingSweep::create($open);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $rows = fn (string $table, array $columns) => DB::table($table)->orderBy('id')->get($columns)
            ->map(fn ($row) => array_map(fn ($v) => (string) $v, (array) $row))->all();

        return [
            'redemptions' => $rows('redemptions', ['id', 'amount_cents', 'status', 'merchant_id']),
            'payouts' => $rows('merchant_payouts', ['id', 'amount_cents', 'status']),
            'purchases' => $rows('credit_purchases', ['id', 'amount_cents', 'status']),
            'corrections' => $rows('corrections', ['id', 'amount_cents', 'status']),
            'ledger' => $rows('credit_ledger_entries', ['id', 'amount_cents', 'type']),
            'employees' => Employee::orderBy('id')->get()->map->balanceCents()->all(),
            'employer' => Employer::firstOrFail()->balanceCents(),
            'merchants' => Merchant::orderBy('id')->get()->map->payableCents()->all(),
        ];
    }
}
