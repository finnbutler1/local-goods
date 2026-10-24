<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The pay codes migration (ADR 0026) runs on the live RDS database. This rolls it
 * back, runs it again on the seeded demo and checks nothing moved: same
 * redemptions, same ledger rows, same balances. It only adds a table and a column with a default.
 */
class PayCodesMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = 'database/migrations/2026_10_26_000001_add_pay_codes.php';

    public function test_existing_redemptions_ledger_rows_and_balances_are_unchanged(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('pay_codes')->count());
        // Every existing redemption is "to go".
        $this->assertSame(DB::table('redemptions')->count(), DB::table('redemptions')->where('channel', 'to_go')->count());
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
