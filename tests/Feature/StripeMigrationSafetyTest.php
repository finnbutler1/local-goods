<?php

namespace Tests\Feature;

use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RollsBackMigrations;
use Tests\TestCase;

/**
 * The Stripe Connect migration runs on the live RDS database. This rolls it
 * back, runs it again on the seeded demo and checks nothing moved: same
 * ledger rows, same balances, every purchase and payout still simulated and
 * no merchant connected to Stripe.
 */
class StripeMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;

    private const MIGRATION = 'database/migrations/2026_10_20_000001_add_stripe_connect_columns.php';

    public function test_existing_ledger_rows_and_balances_are_unchanged(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();

        $this->rollBackSince(self::MIGRATION);
        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, CreditPurchase::where('status', '!=', CreditPurchase::STATUS_SIMULATED)->count());
        $this->assertSame(0, MerchantPayout::where('status', '!=', MerchantPayout::STATUS_SIMULATED)->count());
        $this->assertSame(0, Merchant::whereNotNull('stripe_account_id')->count(), 'New columns start empty');
        $this->assertSame(0, DB::table('stripe_events')->count());
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
