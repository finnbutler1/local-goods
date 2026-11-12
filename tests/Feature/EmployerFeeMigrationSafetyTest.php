<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RollsBackMigrations;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\TestCase;

/**
 * The employer platform fee migration (ADR 0042) runs on the live RDS
 * database. It only adds columns that are 0 or empty: this rolls it back
 * and runs it again on the seeded demo and checks no money row, ledger
 * entry or balance moved, and the seed is still solvent. Runs on SQLite and
 * in CI's MySQL job.
 */
class EmployerFeeMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;
    use SeedsOnAFixedDate;

    private const MIGRATION = 'database/migrations/2026_11_14_000001_add_employer_platform_fee_to_credit_purchases.php';

    private const COLUMNS = ['employer_fee_cents', 'employer_fee_employees', 'employer_fee_unit_cents', 'employer_fee_month', 'employer_fee_key'];

    public function test_existing_money_rows_and_balances_are_unchanged(): void
    {
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();

        $this->rollBackSince(self::MIGRATION);
        foreach (self::COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('credit_purchases', $column), $column);
        }

        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, (int) DB::table('credit_purchases')->sum('employer_fee_cents'));
        $this->assertSame(0, DB::table('credit_purchases')->whereNotNull('employer_fee_key')->orWhereNotNull('employer_fee_month')->count());
        $this->assertSame(0, DB::table('credit_ledger_entries')->where('type', 'employer_platform_fee')->count());
        $this->artisan('ledger:check-solvency', ['--no-record' => true])->assertSuccessful();
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $rows = fn (string $table, array $columns) => DB::table($table)->orderBy('id')->get($columns)
            ->map(fn ($row) => array_map(fn ($v) => (string) $v, (array) $row))->all();

        return [
            'purchases' => $rows('credit_purchases', ['id', 'amount_cents', 'platform_fee_cents', 'status']),
            'ledger' => $rows('credit_ledger_entries', ['id', 'amount_cents', 'type']),
            'payouts' => $rows('merchant_payouts', ['id', 'amount_cents', 'status']),
            'employees' => Employee::orderBy('id')->get()->map->balanceCents()->all(),
            'employer' => Employer::firstOrFail()->balanceCents(),
            'merchants' => Merchant::orderBy('id')->get()->map->payableCents()->all(),
        ];
    }
}
