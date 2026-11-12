<?php

namespace Tests\Feature;

use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Services\CreditLedger;
use App\Support\EmployerPlatformFee;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\TestCase;

/**
 * The employer platform fee (ADR 0042) is decided inside requestPurchase,
 * after the employer lock (lock order: employer first, as StripeFunding).
 * On MySQL: the lock comes before any read the fee depends on, a purchase
 * waits while another request holds the employer, and if a competing fee
 * purchase still committed first, the unique employer_fee_key refuses a
 * second fee for that month. Runs only in the MySQL CI job.
 */
class MysqlEmployerFeeLockTest extends TestCase
{
    use DatabaseMigrations;
    use SeedsOnAFixedDate;

    private Employer $employer;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row locking is only checked against MySQL.');
        }

        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $this->employer = Employer::firstOrFail();
        foreach ($this->employer->employees()->orderBy('id')->take(3)->get() as $employee) {
            app(CreditLedger::class)->allocate($this->employer, $employee, 100, Carbon::parse('2027-02-10 12:00', 'America/New_York')->utc());
        }
        $this->travelTo(Carbon::parse('2027-03-10 12:00', 'America/New_York'));
        config(['payments.employer_platform_fee_enabled' => true]);
        config(['database.connections.mysql_other' => config('database.connections.mysql')]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_other');
        parent::tearDown();
    }

    public function test_the_employer_is_locked_before_anything_the_fee_depends_on_is_read(): void
    {
        $transactions = 0;
        $sql = [];
        Event::listen(TransactionBeginning::class, function () use (&$transactions) {
            $transactions++;
        });
        DB::beforeExecuting(function (string $query) use (&$transactions, &$sql) {
            if ($transactions === 1) {
                $sql[] = $query;
            }
        });

        $purchase = app(CreditLedger::class)->requestPurchase($this->employer, 40000, 'MPG-LOCK-1')->fresh();

        $this->assertSame(1800, $purchase->employer_fee_cents, 'The fee was decided in this transaction');
        $this->assertStringContainsString('from `employers`', $sql[0] ?? '');
        $this->assertStringContainsString('for update', $sql[0] ?? '');
        $reads = array_filter($sql, fn (string $q) => str_contains($q, 'from `credit_purchases`') || str_contains($q, 'from `credit_allocations`'));
        $this->assertNotEmpty($reads, 'The fee reads the purchases and allocations');
        $this->assertGreaterThan(0, min(array_keys($reads)), 'Only after the employer lock');
    }

    public function test_a_purchase_waits_while_another_request_holds_the_employer(): void
    {
        $other = DB::connection('mysql_other');
        $other->beginTransaction();
        $other->table('employers')->where('id', $this->employer->id)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            app(CreditLedger::class)->requestPurchase($this->employer, 40000, 'MPG-LOCK-1');
            $this->fail('The purchase should have waited for the employer lock.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            $other->rollBack();
        }
        $this->assertSame(0, CreditPurchase::where('reference', 'MPG-LOCK-1')->count());

        $this->assertSame(1800, app(CreditLedger::class)->requestPurchase($this->employer, 40000, 'MPG-LOCK-1')->fresh()->employer_fee_cents);
    }

    public function test_while_one_request_decides_the_fee_no_other_purchase_can_be_written(): void
    {
        $key = EmployerPlatformFee::key($this->employer, '2027-03');
        $blocked = null;
        // A competing request tries to write its own fee purchase while ours holds the employer:
        // the new row's foreign key check needs the employer row, so it has to wait.
        DB::beforeExecuting(function (string $sql) use (&$blocked, $key) {
            if ($blocked !== null || ! str_starts_with($sql, 'insert into `credit_purchases`')) {
                return;
            }
            $other = DB::connection('mysql_other');
            $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                $other->table('credit_purchases')->insert([
                    'employer_id' => $this->employer->id, 'amount_cents' => 10000, 'reference' => 'MPG-LOCK-OTHER', 'purchased_at' => now(),
                    'status' => CreditPurchase::STATUS_PENDING, 'employer_fee_cents' => 1800, 'employer_fee_employees' => 3, 'employer_fee_unit_cents' => 600,
                    'employer_fee_month' => '2027-03', 'employer_fee_key' => $key, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $blocked = false;
            } catch (QueryException $e) {
                $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
            }
        });

        $ours = app(CreditLedger::class)->requestPurchase($this->employer, 40000, 'MPG-LOCK-1')->fresh();

        $this->assertTrue($blocked, 'The competing purchase had to wait for the employer lock');
        $this->assertSame(1800, $ours->employer_fee_cents);
        $this->assertSame(1, CreditPurchase::where('employer_fee_key', $key)->count());

        // The next request, after ours committed, sees this month's fee is taken.
        $this->assertSame(0, app(CreditLedger::class)->requestPurchase($this->employer, 10000, 'MPG-LOCK-2')->fresh()->employer_fee_cents);

        // And the unique key is the backstop on MySQL too.
        $this->expectException(UniqueConstraintViolationException::class);
        CreditPurchase::create(['employer_id' => $this->employer->id, 'amount_cents' => 100, 'reference' => 'MPG-LOCK-3', 'purchased_at' => now(),
            'status' => CreditPurchase::STATUS_PENDING, 'employer_fee_cents' => 1800, 'employer_fee_key' => $key]);
    }
}
