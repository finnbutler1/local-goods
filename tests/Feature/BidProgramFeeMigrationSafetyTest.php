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
 * The BID program fee migration (ADR 0044) runs on the live RDS database.
 * It only adds: two empty district columns, two empty tables and one empty
 * ledger column. This rolls it back and runs it again on the seeded demo and
 * checks no district, money row, ledger entry or balance moved, and the seed
 * is still solvent. Runs on SQLite and in CI's MySQL job.
 */
class BidProgramFeeMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;
    use SeedsOnAFixedDate;

    private const MIGRATION = 'database/migrations/2026_11_15_000001_add_bid_program_fee.php';

    public function test_existing_rows_and_balances_are_unchanged(): void
    {
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();

        $this->rollBackSince(self::MIGRATION);
        $this->assertFalse(Schema::hasTable('bid_program_fee_payments'));
        $this->assertFalse(Schema::hasTable('district_program_changes'));
        $this->assertFalse(Schema::hasColumn('districts', 'member_businesses'));
        $this->assertFalse(Schema::hasColumn('districts', 'program_starts_on'));
        $this->assertFalse(Schema::hasColumn('credit_ledger_entries', 'bid_program_fee_payment_id'));

        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('districts')->whereNotNull('member_businesses')->orWhereNotNull('program_starts_on')->count(), 'Nothing is filled in: a platform admin sets them.');
        $this->assertSame(0, DB::table('bid_program_fee_payments')->count());
        $this->assertSame(0, DB::table('district_program_changes')->count());
        $this->assertSame(0, DB::table('credit_ledger_entries')->whereNotNull('bid_program_fee_payment_id')->orWhere('type', 'bid_program_fee')->count());
        $this->artisan('ledger:check-solvency', ['--no-record' => true])->assertSuccessful();
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $rows = fn (string $table, array $columns) => DB::table($table)->orderBy('id')->get($columns)
            ->map(fn ($row) => array_map(fn ($v) => (string) $v, (array) $row))->all();

        return [
            'districts' => $rows('districts', ['id', 'name', 'slug', 'boundary_sha256']),
            'purchases' => $rows('credit_purchases', ['id', 'amount_cents', 'platform_fee_cents', 'employer_fee_cents', 'status']),
            'ledger' => $rows('credit_ledger_entries', ['id', 'amount_cents', 'type']),
            'payouts' => $rows('merchant_payouts', ['id', 'amount_cents', 'status']),
            'employees' => Employee::orderBy('id')->get()->map->balanceCents()->all(),
            'employer' => Employer::firstOrFail()->balanceCents(),
            'merchants' => Merchant::orderBy('id')->get()->map->payableCents()->all(),
        ];
    }
}
