<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BidProgramFeePayment;
use App\Models\District;
use App\Services\Payments\BidProgramFees;
use App\Support\BidProgramFee;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PaysBidProgramFee;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * One BID program fee payment per district per program year (ADR 0044), on
 * MySQL with two connections: a request waits while another holds the
 * district, a competing row for the same year is refused by the unique
 * fee_key, and the request then uses the row that won. Runs only in the
 * MySQL CI job.
 */
class MysqlBidProgramFeeLockTest extends TestCase
{
    use DatabaseMigrations;
    use PaysBidProgramFee;
    use UsesFakeStripe;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row locking is only checked against MySQL.');
        }

        $this->fakeStripe();
        $this->switchOnBidFee();
        $this->district = $this->setUpDistrict(6000, '2026-10-01');
        $this->travelTo(Carbon::parse('2026-11-13 12:00', 'America/New_York'));
        config(['database.connections.mysql_other' => config('database.connections.mysql')]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_other');
        parent::tearDown();
    }

    private function otherRow(): array
    {
        return [
            'district_id' => $this->district->id, 'program_year' => 1, 'period_starts_on' => '2026-10-01', 'period_ends_on' => '2027-09-30',
            'tier' => 'Large', 'member_businesses' => 6000, 'amount_cents' => 500000, 'currency' => 'usd', 'status' => 'pending',
            'fee_key' => BidProgramFee::key($this->district, 1), 'payment_token' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    public function test_a_request_waits_while_another_holds_the_district(): void
    {
        $other = DB::connection('mysql_other');
        $other->beginTransaction();
        $other->table('districts')->where('id', $this->district->id)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            app(BidProgramFees::class)->request($this->district, $this->signIn(Role::BidAdmin));
            $this->fail('The request should have waited for the district lock.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            $other->rollBack();
        }
        $this->assertSame(0, BidProgramFeePayment::count());
    }

    public function test_while_one_request_holds_the_district_no_other_payment_for_it_can_be_written(): void
    {
        $admin = $this->signIn(Role::BidAdmin);
        $blocked = null;
        // A competing request tries to write its own row for the year while ours holds the district:
        // the new row's foreign key check needs the district row, so it has to wait.
        DB::beforeExecuting(function (string $sql) use (&$blocked) {
            if ($blocked !== null || ! str_starts_with($sql, 'insert into `bid_program_fee_payments`')) {
                return;
            }
            $other = DB::connection('mysql_other');
            $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                $other->table('bid_program_fee_payments')->insert($this->otherRow());
                $blocked = false;
            } catch (QueryException $e) {
                $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
            }
        });

        $ours = app(BidProgramFees::class)->request($this->district, $admin);

        $this->assertTrue($blocked, 'The competing payment had to wait for the district lock');
        $this->assertSame(1, BidProgramFeePayment::where('fee_key', BidProgramFee::key($this->district, 1))->count());

        // The next request, after ours committed, gets the same row: one payment for the year.
        $this->assertSame($ours->id, app(BidProgramFees::class)->request($this->district, $admin)->id);
    }

    public function test_the_unique_key_refuses_a_second_payment_for_the_year(): void
    {
        DB::table('bid_program_fee_payments')->insert($this->otherRow());

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('bid_program_fee_payments')->insert($this->otherRow());
    }
}
