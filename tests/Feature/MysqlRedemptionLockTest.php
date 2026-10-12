<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Merchant;
use App\Services\Ai\ClaudeClient;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Production runs on MySQL, where a balance re-check alone does not stop two
 * simultaneous redemptions from overspending. This proves the redemption
 * transaction locks the employee row. Runs only in the MySQL CI job.
 */
class MysqlRedemptionLockTest extends TestCase
{
    // Committed data (not RefreshDatabase's open transaction), so a second
    // connection can see and lock the employee row.
    use DatabaseMigrations;

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

    public function test_redemption_waits_for_a_lock_held_on_the_same_employee(): void
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(5, 'clear'));
        $employee = Employee::firstOrFail();
        $merchant = Merchant::firstOrFail();

        // Another request is in the middle of redeeming for the same employee.
        $other = DB::connection('mysql_other');
        $other->beginTransaction();
        $other->table('employees')->where('id', $employee->id)->lockForUpdate()->first();

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            app(RedemptionService::class)->redeem($employee, $merchant, 650);
            $this->fail('The redemption should have waited for the other lock.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            $other->rollBack();
        }

        // Once the other request finishes, the redemption goes through.
        $before = $employee->balanceCents();
        app(RedemptionService::class)->redeem($employee, $merchant, 650);
        $this->assertSame($before - 650, $employee->balanceCents());
    }
}
