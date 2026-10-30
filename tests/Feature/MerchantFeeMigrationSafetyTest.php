<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantFeeChange;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\RollsBackMigrations;
use Tests\TestCase;

/**
 * The merchant fee migration (ADR 0029) runs on the live RDS database. This
 * rolls it back, runs it again on the seeded demo and checks nothing moved:
 * same money rows, ledger and balances, every fee 0 and no setting row (no
 * fee). Runs on SQLite and in CI's MySQL job.
 */
class MerchantFeeMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;

    private const MIGRATION = 'database/migrations/2026_10_29_000001_add_merchant_fee.php';

    public function test_existing_money_rows_and_balances_are_unchanged_and_every_fee_is_zero(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();

        $this->rollBackSince(self::MIGRATION);
        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('merchant_fee_changes')->count());
        $this->assertSame(0, (int) DB::table('redemptions')->sum('merchant_fee_cents') + (int) DB::table('redemptions')->sum('merchant_fee_bps')
            + (int) DB::table('redemptions')->sum('merchant_fee_fixed_cents'));
        $this->assertSame(0, (int) DB::table('merchant_payouts')->sum('fee_cents'));
        $this->assertSame(0, (int) DB::table('corrections')->sum('fee_cents'));
    }

    public function test_fee_changes_are_append_only(): void
    {
        $change = MerchantFeeChange::create(['fee_bps' => 250, 'fixed_cents' => 0, 'previous_bps' => 0, 'previous_fixed_cents' => 0, 'reason' => 'Test']);

        try {
            $change->update(['fee_bps' => 0]);
            $this->fail('A fee change was edited.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $change->delete();
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
            'corrections' => $rows('corrections', ['id', 'amount_cents', 'status']),
            'ledger' => $rows('credit_ledger_entries', ['id', 'amount_cents', 'type']),
            'employees' => Employee::orderBy('id')->get()->map->balanceCents()->all(),
            'employer' => Employer::firstOrFail()->balanceCents(),
            'merchants' => Merchant::orderBy('id')->get()->map->payableCents()->all(),
        ];
    }
}
