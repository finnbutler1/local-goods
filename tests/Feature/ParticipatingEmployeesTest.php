<?php

namespace Tests\Feature;

use App\Enums\Occasion;
use App\Models\CreditAllocation;
use App\Models\Employee;
use App\Models\Employer;
use App\Support\ParticipatingEmployees;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\TestCase;

/**
 * Who counts as a participating employee for the employer platform fee
 * (ADR 0042): MODEL.md's "employees who receive credits each month", for the
 * calendar month before the purchase, in DC time.
 */
class ParticipatingEmployeesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOnAFixedDate;

    private Employer $employer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $this->employer = Employer::firstOrFail();
    }

    private function gave(Employee $employee, string $dcTime, ?Employer $employer = null, Occasion $occasion = Occasion::Monthly): void
    {
        CreditAllocation::create([
            'employer_id' => ($employer ?? $this->employer)->id, 'employee_id' => $employee->id, 'amount_cents' => 100,
            'allocated_at' => Carbon::parse($dcTime, 'America/New_York')->utc(), 'occasion' => $occasion,
        ]);
    }

    public function test_it_counts_different_employees_who_received_credits_in_the_previous_calendar_month_dc_time(): void
    {
        [$a, $b, $c, $d, $e] = $this->employer->employees()->orderBy('id')->take(5)->get()->all();

        $this->gave($a, '2027-02-01 00:05');
        $this->gave($a, '2027-02-15 12:00', occasion: Occasion::Recognition); // twice: counted once
        $this->gave($b, '2027-02-28 23:30', occasion: Occasion::Birthday);    // Feb in DC, already March in UTC
        $this->gave($c, '2027-01-31 23:59');                                   // January
        $this->gave($d, '2027-03-01 00:00');                                   // March
        $this->gave($e, '2027-03-09 12:00');                                   // this month so far

        $this->assertSame(2, ParticipatingEmployees::lastMonth($this->employer, Carbon::parse('2027-03-10 12:00', 'America/New_York')));
        $this->assertSame(2, ParticipatingEmployees::lastMonth($this->employer, Carbon::parse('2027-03-01 05:00', 'UTC')), 'Midnight on Mar 1 in DC');
        $this->assertSame(1, ParticipatingEmployees::lastMonth($this->employer, Carbon::parse('2027-03-01 04:59', 'UTC')), 'Still Feb 28 in DC, so January');
        $this->assertSame(2, ParticipatingEmployees::lastMonth($this->employer, Carbon::parse('2027-04-01 12:00', 'America/New_York')), 'March');
    }

    public function test_other_employers_staff_are_not_counted(): void
    {
        $other = $this->employer->replicate();
        $other->name = 'Another Fictional Employer';
        $other->save();
        $theirs = Employee::create(['employer_id' => $other->id, 'display_name' => 'Fictional Person']);

        $this->gave($theirs, '2027-02-10 12:00', $other);
        $this->gave($this->employer->employees()->orderBy('id')->firstOrFail(), '2027-02-10 12:00');

        $at = Carbon::parse('2027-03-10 12:00', 'America/New_York');
        $this->assertSame(1, ParticipatingEmployees::lastMonth($this->employer, $at));
        $this->assertSame(1, ParticipatingEmployees::lastMonth($other, $at));
    }

    public function test_nobody_last_month_is_zero(): void
    {
        $this->assertSame(0, ParticipatingEmployees::lastMonth($this->employer, Carbon::parse('2027-06-10 12:00', 'America/New_York')));
    }
}
