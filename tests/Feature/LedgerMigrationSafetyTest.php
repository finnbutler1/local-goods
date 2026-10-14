<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The ledger accounts migration runs on the live RDS database, which already
 * has entries. This rolls back just that migration, writes entries the way
 * the old schema held them, runs it again, and checks nothing moved: same
 * rows, same employee on each, same amounts, same balance for everyone.
 * Runs in both the SQLite and the MySQL CI jobs.
 */
class LedgerMigrationSafetyTest extends TestCase
{
    // Real migrations (not RefreshDatabase's transaction): MySQL commits
    // schema changes immediately, so they can't be rolled back that way.
    use DatabaseMigrations;

    private const MIGRATION = 'database/migrations/2026_10_17_000002_add_accounts_to_credit_ledger_entries.php';

    public function test_existing_entries_and_every_employee_balance_are_unchanged(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();
        $employees = $this->legacyLedger();

        $before = $this->snapshot($employees);
        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();
        $after = $this->snapshot($employees);

        $this->assertSame($before, $after);
        $this->assertSame(['employee_id' => 1, 'employer_id' => null, 'merchant_id' => null],
            (array) DB::table('credit_ledger_entries')->select('employee_id', 'employer_id', 'merchant_id')->orderBy('id')->first());
    }

    public function test_rolling_back_in_production_refuses_to_drop_employer_or_merchant_entries(): void
    {
        $employees = $this->legacyLedger();
        DB::table('credit_ledger_entries')->insert([
            'employer_id' => DB::table('employers')->value('id'), 'amount_cents' => 50000,
            'type' => 'purchase', 'description' => 'Credits purchased', 'created_at' => now(),
        ]);
        $before = $this->snapshot($employees);

        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->app->make('migrator')->rollback([database_path('migrations')], ['step' => 1]);
            $this->fail('The rollback was allowed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to roll back', $e->getMessage());
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertSame($before, $this->snapshot($employees));
    }

    /**
     * Two employees with every kind of entry the old schema had.
     *
     * @return list<int>
     */
    private function legacyLedger(): array
    {
        $bid = DB::table('bids')->insertGetId(['name' => 'Golden Triangle', 'city' => 'Washington, DC']);
        $employer = DB::table('employers')->insertGetId(['bid_id' => $bid, 'name' => 'Meridian Policy Group', 'monthly_allowance_cents' => 15000]);
        $merchant = DB::table('merchants')->insertGetId(['bid_id' => $bid, 'name' => 'Press Room Deli', 'category' => 'restaurant', 'address' => '1 Test St NW']);
        $employees = [];

        foreach ([['A', 2], ['B', 3]] as [$name, $months]) {
            $employee = DB::table('employees')->insertGetId(['employer_id' => $employer, 'display_name' => "Employee {$name}"]);
            $employees[] = $employee;

            for ($m = 0; $m < $months; $m++) {
                DB::table('credit_ledger_entries')->insert(['employee_id' => $employee, 'amount_cents' => 15000, 'type' => 'allowance', 'description' => 'Allowance', 'created_at' => now()->subMonths($m)]);
            }

            foreach ([['approved', 1387, null], ['approved', 2000, 'review_approved'], ['rejected', 4500, 'review_rejected']] as [$status, $cents, $review]) {
                $redemption = DB::table('redemptions')->insertGetId([
                    'employee_id' => $employee, 'merchant_id' => $merchant, 'amount_cents' => $cents,
                    'status' => $status, 'scored_by' => 'seed', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('credit_ledger_entries')->insert(['employee_id' => $employee, 'amount_cents' => -$cents, 'type' => 'redemption', 'redemption_id' => $redemption, 'description' => 'Redemption', 'created_at' => now()]);

                if ($review !== null) {
                    DB::table('credit_ledger_entries')->insert(['employee_id' => $employee, 'amount_cents' => $review === 'review_rejected' ? $cents : 0, 'type' => $review, 'redemption_id' => $redemption, 'description' => 'Decision', 'decided_by' => 'Sam Taylor', 'created_at' => now()]);
                }
            }
        }

        return $employees;
    }

    /**
     * @param  list<int>  $employees
     * @return array<string, mixed>
     */
    private function snapshot(array $employees): array
    {
        return [
            'rows' => DB::table('credit_ledger_entries')->whereNotNull('employee_id')->orderBy('id')
                ->get(['id', 'employee_id', 'amount_cents', 'type', 'redemption_id'])
                ->map(fn ($row) => array_map(fn ($value) => $value === null ? null : (string) $value, (array) $row))->all(),
            'balances' => collect($employees)->mapWithKeys(fn ($id) => [$id => Employee::findOrFail($id)->balanceCents()])->all(),
        ];
    }
}
