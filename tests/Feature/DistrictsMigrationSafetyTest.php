<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RollsBackMigrations;
use Tests\TestCase;

/**
 * The districts, partnerships and applications migrations (ADR 0031) run
 * on the live RDS database. This rolls them back, runs them again on the
 * seeded demo and checks no money row, ledger entry or balance moved, and
 * that the backfill placed every demo merchant inside the boundary. Runs on
 * SQLite and in CI's MySQL job.
 */
class DistrictsMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;

    /** Newest first, the order a rollback undoes them. */
    public const MIGRATIONS = [
        'database/migrations/2026_10_30_000003_add_merchant_joining.php',
        'database/migrations/2026_10_30_000002_add_partnerships.php',
        'database/migrations/2026_10_30_000001_add_districts.php',
    ];

    public function test_existing_money_rows_and_balances_are_unchanged_and_the_demo_is_placed(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();

        $this->rollBack();
        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());

        $district = District::default();
        $this->assertSame($district->id, Employer::sole()->district_id);
        foreach (Merchant::all() as $merchant) {
            $this->assertSame($district->id, $merchant->district_id, $merchant->name);
            $this->assertNotNull($merchant->live_at, $merchant->name);
            $this->assertTrue($district->boundary()->contains((float) $merchant->latitude, (float) $merchant->longitude), $merchant->name);
            $this->assertSame(DatabaseSeeder::DEMO_MERCHANTS[$this->key($merchant)][2], $merchant->address);
        }
    }

    public function test_the_migration_moves_the_two_outside_addresses_and_back(): void
    {
        $this->seed(DatabaseSeeder::class);
        DB::table('merchants')->where('name', 'Connecticut Noodle Bar')->update(['address' => '1700 block of Connecticut Ave NW']);

        $this->rollBack();
        $this->assertSame('1500 block of H St NW', DB::table('merchants')->where('name', 'Lafayette Cycle Studio')->value('address'));

        $this->migrateAgain();
        $this->assertSame('1100 block of Connecticut Ave NW', DB::table('merchants')->where('name', 'Connecticut Noodle Bar')->value('address'));
        $this->assertSame('1700 block of H St NW', DB::table('merchants')->where('name', 'Lafayette Cycle Studio')->value('address'));
    }

    public function test_a_merchant_outside_the_demo_is_left_unplaced(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->rollBack();
        $other = DB::table('merchants')->insertGetId(['bid_id' => DB::table('bids')->value('id'), 'name' => 'Not In The Demo', 'category' => 'cafe', 'address' => '1 Test St NW']);

        $this->migrateAgain();

        $this->assertNull(Merchant::find($other)->district_id);
        $this->assertNull(Merchant::find($other)->live_at);
    }

    /** Unwinds the three district migrations and everything after them. */
    private function rollBack(): void
    {
        $this->rollBackSince(self::MIGRATIONS[array_key_last(self::MIGRATIONS)]);
    }

    private function key(Merchant $merchant): string
    {
        return (string) collect(DatabaseSeeder::DEMO_MERCHANTS)->search(fn ($m) => $m[0] === $merchant->name);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $rows = fn (string $table, array $columns) => DB::table($table)->orderBy('id')->get($columns)
            ->map(fn ($row) => array_map(fn ($v) => (string) $v, (array) $row))->all();

        return [
            'redemptions' => $rows('redemptions', ['id', 'amount_cents', 'status', 'merchant_id']),
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
