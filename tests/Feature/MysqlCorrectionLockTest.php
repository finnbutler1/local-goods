<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CreditLedgerEntry;
use App\Models\Employer;
use App\Services\Corrections\CorrectionDecisions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesCorrections;
use Tests\TestCase;

/**
 * Production runs on MySQL, where a re-check alone does not stop two
 * approvals at the same moment from both passing (ADR 0028). This proves an
 * approval locks the item first: while another request holds the
 * redemption (or the employer) row, it waits, and when it can't get the
 * lock it changes nothing. Runs only in the MySQL CI job.
 */
class MysqlCorrectionLockTest extends TestCase
{
    use DatabaseMigrations;
    use MakesCorrections;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row locking is only checked against MySQL.');
        }

        $this->seed(DatabaseSeeder::class);
        config(['database.connections.mysql_other' => config('database.connections.mysql')]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_other');
        parent::tearDown();
    }

    public function test_a_refund_approval_waits_for_a_lock_held_on_the_same_redemption(): void
    {
        $redemption = $this->cleared(1250);
        $request = $this->refundRequest($redemption);

        // Another approval is in the middle of refunding the same redemption.
        $other = DB::connection('mysql_other');
        $other->beginTransaction();
        $other->table('redemptions')->where('id', $redemption->id)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            app(CorrectionDecisions::class)->approveRedemptionRefund($request, $this->admin(), 'Refund.', amountCents: 1250);
            $this->fail('The approval should have waited for the other lock.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            $other->rollBack();
        }

        $this->assertSame(Correction::STATUS_REQUESTED, $request->fresh()->status, 'Nothing was decided or written');
        $this->assertSame(0, CreditLedgerEntry::where('correction_id', $request->id)->count());

        app(CorrectionDecisions::class)->approveRedemptionRefund($request, $this->admin(), 'Refund.', amountCents: 1250);
        $this->assertSame(Correction::STATUS_COMPLETED, $request->fresh()->status);
    }

    public function test_an_employer_refund_waits_for_a_lock_held_on_the_employer(): void
    {
        $employer = Employer::firstOrFail();
        $request = Correction::create(['type' => Correction::TYPE_EMPLOYER_REFUND, 'status' => Correction::STATUS_REQUESTED, 'open_key' => 'employer:'.$employer->id,
            'requested_by_role' => Role::EmployerAdmin->value, 'reason_category' => 'other', 'employer_id' => $employer->id]);

        // Someone is giving out credits (allocateMany locks the employer row) at the same moment.
        $other = DB::connection('mysql_other');
        $other->beginTransaction();
        $other->table('employers')->where('id', $employer->id)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            app(CorrectionDecisions::class)->approveEmployerRefund($request, $this->admin(), 'Refund.', 1000);
            $this->fail('The approval should have waited for the other lock.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            $other->rollBack();
        }

        $this->assertSame(Correction::STATUS_REQUESTED, $request->fresh()->status);
        $this->assertSame(0, CreditLedgerEntry::where('correction_id', $request->id)->count());
    }
}
