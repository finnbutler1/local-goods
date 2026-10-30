<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RollsBackMigrations;
use Tests\TestCase;

/**
 * The corrections migration (ADR 0028) runs on the live RDS database. This
 * rolls it back, runs it again on the seeded demo and checks nothing moved:
 * same redemptions, payouts, purchases, ledger rows and balances. It only
 * adds three tables and one nullable column. Runs on SQLite and in CI's
 * MySQL job.
 */
class CorrectionsMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;

    private const MIGRATION = 'database/migrations/2026_10_28_000001_add_corrections.php';

    public function test_existing_money_rows_and_balances_are_unchanged(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();

        $this->rollBackSince(self::MIGRATION);
        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('corrections')->count());
        $this->assertSame(0, DB::table('credit_ledger_entries')->whereNotNull('correction_id')->count());
    }

    public function test_the_database_allows_one_open_request_per_item(): void
    {
        $this->seed(DatabaseSeeder::class);
        $row = ['type' => 'redemption_refund', 'status' => 'requested', 'open_key' => 'redemption:999', 'requested_by_role' => 'employee',
            'reason_category' => 'other', 'created_at' => now(), 'updated_at' => now()];
        DB::table('corrections')->insert($row);

        // Closed requests keep no key, so any number of them may exist.
        DB::table('corrections')->insert(['status' => 'rejected', 'open_key' => null] + $row);
        DB::table('corrections')->insert(['status' => 'completed', 'open_key' => null] + $row);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('corrections')->insert($row);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $rows = fn (string $table, array $columns) => DB::table($table)->orderBy('id')->get($columns)
            ->map(fn ($row) => array_map(fn ($v) => (string) $v, (array) $row))->all();

        return [
            'redemptions' => $rows('redemptions', ['id', 'amount_cents', 'status']),
            'payouts' => $rows('merchant_payouts', ['id', 'amount_cents', 'status']),
            'purchases' => $rows('credit_purchases', ['id', 'amount_cents', 'status']),
            'ledger' => $rows('credit_ledger_entries', ['id', 'amount_cents', 'type']),
            'employees' => Employee::orderBy('id')->get()->map->balanceCents()->all(),
            'employer' => Employer::firstOrFail()->balanceCents(),
            'merchants' => Merchant::orderBy('id')->get()->map->payableCents()->all(),
        ];
    }
}
