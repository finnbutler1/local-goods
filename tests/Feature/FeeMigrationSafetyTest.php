<?php

namespace Tests\Feature;

use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RollsBackMigrations;
use Tests\TestCase;

/**
 * The platform fee migration runs on the live RDS database. This rolls it
 * back, runs it again on the seeded demo and checks nothing moved: same
 * ledger rows, same balances, every purchase with no fee and no ledger line
 * on the platform account.
 */
class FeeMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;

    private const MIGRATION = 'database/migrations/2026_10_23_000001_add_platform_fee_to_purchases_and_ledger.php';

    public function test_existing_ledger_rows_and_balances_are_unchanged(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();

        $this->rollBackSince(self::MIGRATION);
        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, CreditPurchase::where('platform_fee_cents', '!=', 0)->count(), 'Existing purchases have no fee');
        $this->assertSame(0, DB::table('credit_ledger_entries')->whereNotNull('platform')->count(), 'No existing line moves to the platform account');
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'rows' => DB::table('credit_ledger_entries')->orderBy('id')->get(['id', 'employee_id', 'employer_id', 'merchant_id', 'amount_cents', 'type'])
                ->map(fn ($row) => array_map(fn ($v) => $v === null ? null : (string) $v, (array) $row))->all(),
            'purchases' => DB::table('credit_purchases')->orderBy('id')->get(['id', 'amount_cents', 'status'])
                ->map(fn ($row) => array_map(fn ($v) => (string) $v, (array) $row))->all(),
            'employees' => Employee::orderBy('id')->get()->map->balanceCents()->all(),
            'employer' => Employer::firstOrFail()->balanceCents(),
            'merchants' => Merchant::orderBy('id')->get()->map->payableCents()->all(),
        ];
    }
}
