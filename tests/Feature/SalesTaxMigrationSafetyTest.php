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
 * The sales tax migration (ADR 0027) runs on the live RDS database. This rolls it
 * back, runs it again on the seeded demo and checks nothing moved: same
 * redemptions, same ledger rows, same balances. It only adds columns, and
 * gives every existing product a tax category from its merchant.
 */
class SalesTaxMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;

    private const MIGRATION = 'database/migrations/2026_10_27_000001_add_sales_tax.php';

    public function test_existing_redemptions_ledger_rows_and_balances_are_unchanged(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();

        $this->rollBackSince(self::MIGRATION);
        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('merchant_products')->whereNull('tax_category')->count());
    }

    public function test_existing_products_get_their_merchants_default_category(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->rollBackSince(self::MIGRATION);
        $this->migrateAgain();

        $byMerchant = DB::table('merchant_products')->join('merchants', 'merchants.id', '=', 'merchant_products.merchant_id')
            ->get(['merchants.category', 'merchant_products.tax_category'])
            ->groupBy('category')->map(fn ($rows) => $rows->pluck('tax_category')->unique()->values()->all())->all();

        $this->assertSame(['prepared_food'], $byMerchant['cafe']);
        $this->assertSame(['prepared_food'], $byMerchant['restaurant']);
        $this->assertSame(['general'], $byMerchant['retail']);
        $this->assertSame(['general'], $byMerchant['fitness']);
        $this->assertSame(['exempt'], $byMerchant['salon']);
        $this->assertNull(DB::table('redemptions')->whereNotNull('tax_basis')->value('id'), 'old rows are "before tax display"');
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
