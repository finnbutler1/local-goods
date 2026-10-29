<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\Employer;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\CreditLedger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * A probe, not a guarantee. Approving an employer refund reads the employer's
 * balance after a plain SELECT (Correction::refresh in claim()) and before
 * the employer row is locked. On MySQL's default REPEATABLE READ, a balance
 * read after that SELECT can use an older snapshot than a credit allocation
 * that committed in between, so a refund hold could be larger than what is
 * really left unallocated. This commits an allocation right after that
 * SELECT and checks the employer is not left with a negative balance.
 *
 * It protects the employer's money, not any employee's balance, so it is
 * outside the README promise that MissedFraudLossCapTest proves. If it
 * fails, the failure message says so: that is the finding, reported to
 * Finn, not fixed here. Runs only in the MySQL CI job.
 */
class MysqlEmployerRefundSnapshotProbeTest extends TestCase
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

    public function test_an_allocation_committed_during_an_employer_refund_approval_does_not_leave_the_employer_negative(): void
    {
        $employer = Employer::firstOrFail();
        $unallocated = $employer->balanceCents();
        $this->assertGreaterThan(1000, $unallocated);

        $request = Correction::create([
            'type' => Correction::TYPE_EMPLOYER_REFUND, 'status' => Correction::STATUS_REQUESTED,
            'open_key' => Correction::openKeyFor(Correction::TYPE_EMPLOYER_REFUND, $employer->id),
            'requested_by_role' => Role::EmployerAdmin->value, 'reason_category' => 'bought_too_much', 'employer_id' => $employer->id,
        ]);

        // Commit "an allocation of everything left" on another connection
        // right after the approval's plain SELECT of the correction.
        $done = false;
        DB::listen(function ($query) use (&$done, $employer, $unallocated) {
            if (! $done && str_starts_with($query->sql, 'select') && str_contains($query->sql, '`corrections`')) {
                $done = true;
                DB::connection('mysql_other')->table('credit_ledger_entries')->insert([
                    'employer_id' => $employer->id, 'amount_cents' => -$unallocated, 'type' => 'allocation_out',
                    'description' => 'Probe: a concurrent allocation', 'created_at' => now(),
                ]);
            }
        });

        try {
            app(CorrectionDecisions::class)->approveEmployerRefund($request, $this->admin(), 'Unspent credits back.', $unallocated);
        } catch (InvalidArgumentException) {
            // Refused because the real balance was seen: the safe outcome.
        }

        $this->assertTrue($done, 'the probe never fired');
        $this->assertGreaterThanOrEqual(0, $employer->fresh()->balanceCents(),
            'FINDING A reproduced: the refund hold was approved against a stale balance, leaving the employer negative. See CorrectionDecisions::approveEmployerRefund.');
    }
}
