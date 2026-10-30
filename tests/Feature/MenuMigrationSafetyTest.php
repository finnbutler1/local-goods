<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RollsBackMigrations;
use Tests\TestCase;

/**
 * The product menu migration runs on the live RDS database. This rolls it
 * back, runs it again on the seeded demo and checks nothing moved: same
 * redemptions, same ledger rows, same balances. It only adds tables.
 */
class MenuMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;

    private const MIGRATION = 'database/migrations/2026_10_25_000001_create_product_menu_tables.php';

    public function test_existing_redemptions_ledger_rows_and_balances_are_unchanged(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();

        $this->rollBackSince(self::MIGRATION);
        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('redemption_items')->count());
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'redemptions' => DB::table('redemptions')->orderBy('id')->get(['id', 'amount_cents', 'status'])
                ->map(fn ($row) => array_map(fn ($v) => (string) $v, (array) $row))->all(),
            'rows' => DB::table('credit_ledger_entries')->orderBy('id')->get(['id', 'amount_cents', 'type'])
                ->map(fn ($row) => array_map(fn ($v) => (string) $v, (array) $row))->all(),
            'employees' => Employee::orderBy('id')->get()->map->balanceCents()->all(),
            'employer' => Employer::firstOrFail()->balanceCents(),
            'merchants' => Merchant::orderBy('id')->get()->map->payableCents()->all(),
        ];
    }
}
