<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RollsBackMigrations;
use Tests\TestCase;

/**
 * The demo activity migration (ADR 0032) runs on the live RDS database.
 * This rolls it back and runs it again on the seeded demo and checks no
 * money row, ledger entry or balance moved. Runs on SQLite and in CI's
 * MySQL job.
 */
class DemoActivityMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;

    private const MIGRATION = 'database/migrations/2026_10_31_000001_add_demo_activity.php';

    public function test_existing_money_rows_and_balances_are_unchanged(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();

        $this->rollBackSince(self::MIGRATION);
        $this->assertFalse(Schema::hasTable('demo_activity_actions'));
        $this->assertFalse(Schema::hasTable('demo_activity_days'));

        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('demo_activity_days')->count());
        $this->assertSame(0, DB::table('demo_activity_actions')->count());
    }

    public function test_a_slot_can_only_be_claimed_once_a_day(): void
    {
        $day = DB::table('demo_activity_days')->insertGetId(['date' => '2026-11-02', 'seed' => 1, 'plan' => '[]']);
        $row = ['day_id' => $day, 'slot' => 'r01', 'kind' => 'redemption', 'status' => 'claimed'];
        DB::table('demo_activity_actions')->insert($row);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('demo_activity_actions')->insert($row);
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
