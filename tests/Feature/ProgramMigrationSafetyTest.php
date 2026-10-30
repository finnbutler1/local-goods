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
 * The employer program migration runs on the live RDS database. This rolls
 * it back, seeds the demo the way the old schema held it, runs it again and
 * checks nothing moved: same ledger rows, same balance for every account,
 * and every existing purchase counts as paid (simulated), as it was.
 */
class ProgramMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;

    private const MIGRATION = 'database/migrations/2026_10_19_000001_add_employer_program_columns.php';

    public function test_existing_ledger_rows_and_balances_are_unchanged(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();

        $this->rollBackSince(self::MIGRATION);
        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, CreditPurchase::where('status', '!=', CreditPurchase::STATUS_SIMULATED)->count());
        $this->assertNull(Employer::firstOrFail()->monthly_budget_cents, 'New columns start empty');
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'rows' => DB::table('credit_ledger_entries')->orderBy('id')->get(['id', 'employee_id', 'employer_id', 'merchant_id', 'amount_cents', 'type'])
                ->map(fn ($row) => array_map(fn ($v) => $v === null ? null : (string) $v, (array) $row))->all(),
            'employees' => Employee::orderBy('id')->get()->map->balanceCents()->all(),
            'employer' => Employer::firstOrFail()->balanceCents(),
            'merchants' => Merchant::orderBy('id')->get()->map->payableCents()->all(),
        ];
    }
}
