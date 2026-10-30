<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RollsBackMigrations;
use Tests\TestCase;

/**
 * The fraud gate migration (ADR 0016) runs on the live RDS database. This
 * rolls it back, adds redemptions decided the old way, runs it again and
 * checks nothing moved: same ledger rows and balances, and one backfilled
 * decision per redemption a person had already decided.
 */
class DecisionsMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;

    private const MIGRATION = 'database/migrations/2026_10_21_000001_create_redemption_decisions_table.php';

    public function test_existing_rows_are_unchanged_and_past_decisions_are_backfilled(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->rollBackSince(self::MIGRATION);

        $reviewer = User::factory()->role(Role::BidAdmin)->create(['name' => 'Sam Taylor']);
        $employee = Employee::orderBy('id')->firstOrFail();
        $merchant = Merchant::firstOrFail();
        $decided = [];
        foreach (['approved' => 'hold', 'rejected' => 'hold'] as $status => $ai) {
            $decided[$status] = DB::table('redemptions')->insertGetId([
                'employee_id' => $employee->id, 'merchant_id' => $merchant->id, 'amount_cents' => 2000, 'status' => $status,
                'risk_score' => 91, 'ai_decision' => $ai, 'scored_by' => 'claude-opus-5-5', 'reviewed_by' => 'Sam Taylor',
                'reviewed_by_user_id' => $reviewer->id, 'reviewed_at' => '2026-10-15 14:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $before = $this->snapshot();

        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());
        $rows = DB::table('redemption_decisions')->orderBy('redemption_id')->get();
        $this->assertCount(2, $rows, 'Only redemptions a person decided');
        $this->assertSame(['approve', 'deny'], $rows->pluck('decision')->all());
        $this->assertSame([$decided['approved'], $decided['rejected']], $rows->pluck('redemption_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([null, 'employee'], $rows->pluck('credit_returned_to')->all());
        $this->assertSame(['Sam Taylor', 'Sam Taylor'], $rows->pluck('decided_by_name')->all());
        $this->assertSame([91, 91], $rows->pluck('ai_risk_score')->map(fn ($v) => (int) $v)->all());
        $this->assertTrue($rows->every(fn ($r) => (bool) $r->backfilled && $r->reason === 'Decided before reasons were recorded.'));
        $this->assertSame(0, (int) DB::table('merchant_payouts')->sum('attempts'));
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'rows' => DB::table('credit_ledger_entries')->orderBy('id')->get(['id', 'employee_id', 'employer_id', 'merchant_id', 'amount_cents', 'type'])
                ->map(fn ($row) => array_map(fn ($v) => $v === null ? null : (string) $v, (array) $row))->all(),
            'redemptions' => Redemption::orderBy('id')->get(['id', 'status', 'reviewed_by'])->toArray(),
            'employees' => Employee::orderBy('id')->get()->map->balanceCents()->all(),
            'employer' => Employer::firstOrFail()->balanceCents(),
            'merchants' => Merchant::orderBy('id')->get()->map->payableCents()->all(),
        ];
    }
}
