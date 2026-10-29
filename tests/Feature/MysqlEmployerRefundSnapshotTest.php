<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CreditLedgerEntry;
use App\Models\Employer;
use App\Services\Corrections\CorrectionDecisions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Approving an employer refund checks the employer's unallocated balance. On
 * MySQL's default REPEATABLE READ a transaction's snapshot starts at its first
 * plain SELECT, so the employer row (and its purchases) must be locked before
 * anything else is read: an allocation that committed before we got the lock
 * is then seen, and the refund is refused instead of held against a stale
 * balance (which left the employer negative). Regression test for that
 * ordering in CorrectionDecisions::approveEmployerRefund. It protects the
 * employer's money, not an employee's balance. Runs only in the MySQL CI job.
 */
class MysqlEmployerRefundSnapshotTest extends TestCase
{
    use DatabaseMigrations;
    use MakesCorrections;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('A snapshot read is only checked against MySQL.');
        }

        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->withoutSeededCorrections();
        config(['database.connections.mysql_other' => config('database.connections.mysql')]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_other');
        parent::tearDown();
    }

    public function test_an_allocation_committed_before_the_employer_lock_is_seen_and_the_refund_is_refused(): void
    {
        $employer = Employer::firstOrFail();
        $unallocated = $employer->balanceCents();
        $this->assertGreaterThan(1000, $unallocated);

        $request = Correction::create([
            'type' => Correction::TYPE_EMPLOYER_REFUND, 'status' => Correction::STATUS_REQUESTED,
            'open_key' => Correction::openKeyFor(Correction::TYPE_EMPLOYER_REFUND, $employer->id),
            'requested_by_role' => Role::EmployerAdmin->value, 'reason_category' => 'bought_too_much', 'employer_id' => $employer->id,
        ]);
        $holds = fn () => CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_EMPLOYER_REFUND_HOLD)->count();

        // Another request gives out everything left and commits just before
        // the approval's lock on the employer is taken.
        $done = false;
        DB::beforeExecuting(function (string $sql) use (&$done, $employer, $unallocated) {
            if (! $done && str_contains($sql, 'from `employers`') && str_contains($sql, 'for update')) {
                $done = true;
                DB::connection('mysql_other')->table('credit_ledger_entries')->insert([
                    'employer_id' => $employer->id, 'amount_cents' => -$unallocated, 'type' => 'allocation_out',
                    'description' => 'A concurrent allocation', 'created_at' => now(),
                ]);
            }
        });

        try {
            app(CorrectionDecisions::class)->approveEmployerRefund($request, $this->admin(), 'Unspent credits back.', $unallocated);
            $this->fail('The refund was approved against a balance that had already been given out.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('of unspent credits can be refunded', $e->getMessage());
        }

        $this->assertTrue($done, 'the concurrent allocation never ran');
        $this->assertSame(0, $employer->fresh()->balanceCents());
        $this->assertSame(0, $holds(), 'nothing was held');
        $this->assertSame(Correction::STATUS_REQUESTED, $request->fresh()->status, 'the refused request is still open');
    }
}
