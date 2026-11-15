<?php

namespace Tests\Feature;

use App\Enums\Occasion;
use App\Enums\Role;
use App\Http\Controllers\Employer\PayrollReportController;
use App\Models\CreditAllocation;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Employer;
use App\Services\CreditLedger;
use App\Services\Sandbox\Sandbox;
use App\Services\Sandbox\SandboxRoutes;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesSandboxes;
use Tests\TestCase;

/**
 * The employer's payroll report: a CSV of credits actually given to each
 * employee in a date range (DC time, both days included), with totals per
 * employee and a grand total that match the ledger. Reads only; scoped to the
 * employer's own employees; judges can download it; inside a sandbox it reads
 * only that sandbox's file.
 */
class PayrollReportTest extends TestCase
{
    use RefreshDatabase, SeedsOnAFixedDate, UsesSandboxes;

    private function employer(): Employer
    {
        return Employer::orderBy('id')->firstOrFail();
    }

    /** @return list<list<string>> */
    private function csv(TestResponse $response): array
    {
        return array_map(str_getcsv(...), array_values(array_filter(explode("\n", $response->streamedContent()))));
    }

    /** Gives credits at an exact moment, through the real ledger. */
    private function giveAt(Employee $employee, int $cents, string $utc): CreditAllocation
    {
        $employer = Employer::findOrFail($employee->employer_id);
        $ledger = app(CreditLedger::class);
        $ledger->purchase($employer, $cents, 'PAYROLL-TEST-'.bin2hex(random_bytes(3)), Carbon::parse($utc, 'UTC'));

        return $ledger->allocate($employer, $employee, $cents, Carbon::parse($utc, 'UTC'), Occasion::Recognition);
    }

    private function allowanceCents(Carbon $from, Carbon $to, ?int $employeeId = null): int
    {
        return (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_ALLOWANCE)
            ->whereIn('employee_id', $this->employer()->employees()->pluck('id'))
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->sum('amount_cents');
    }

    public function test_totals_per_employee_and_the_grand_total_match_the_ledger(): void
    {
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::EmployerAdmin);

        $response = $this->get(route('employer.payroll.csv', ['from' => '2026-08-01', 'to' => '2026-10-31']))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('filename=local-goods-payroll-2026-08-01-to-2026-10-31.csv', $response->headers->get('content-disposition'));
        $rows = $this->csv($response);
        $this->assertSame(PayrollReportController::HEADERS, $rows[0]);

        $from = Carbon::parse('2026-08-01', 'America/New_York')->startOfDay();
        $to = Carbon::parse('2026-10-31', 'America/New_York')->endOfDay();
        $grand = end($rows);
        $this->assertSame('Grand total', $grand[0]);
        $this->assertGreaterThan(0, $this->allowanceCents($from, $to), 'The seed gave credits in this range, so the check is not empty.');
        $this->assertSame(number_format($this->allowanceCents($from, $to) / 100, 2, '.', ''), $grand[4]);

        $totals = array_filter($rows, fn ($r) => str_starts_with($r[0], 'Total for '));
        $this->assertNotEmpty($totals);
        foreach ($totals as $row) {
            $employee = Employee::findOrFail((int) ltrim($row[1], '#'));
            $this->assertSame('Total for '.$employee->initials(), $row[0]);
            $this->assertSame(number_format($this->allowanceCents($from, $to, $employee->id) / 100, 2, '.', ''), $row[4], $row[1]);
            // Each employee's rows add up to their total.
            $mine = array_filter($rows, fn ($r) => $r[1] === $row[1] && ! str_starts_with($r[0], 'Total for '));
            $this->assertSame((float) $row[4], round(array_sum(array_map(fn ($r) => (float) $r[4], $mine)), 2));
        }

        // Initials and numbers only, never a name.
        $this->assertStringNotContainsString(Employee::orderBy('id')->firstOrFail()->display_name, $response->streamedContent());
    }

    public function test_only_credits_given_count_not_credits_bought_and_not_yet_given(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employer = $this->employer();
        $ledger = app(CreditLedger::class);
        // Bought and paid, never given; and a purchase still waiting for payment.
        $ledger->purchase($employer, 123400, 'PAYROLL-BOUGHT');
        $ledger->requestPurchase($employer, 56700, 'PAYROLL-PENDING');
        $this->signIn(Role::EmployerAdmin);

        $today = now()->setTimezone('America/New_York')->format('Y-m-d');
        $rows = $this->csv($this->get(route('employer.payroll.csv', ['from' => $today, 'to' => $today]))->assertOk());

        $this->assertSame([PayrollReportController::HEADERS, ['Grand total', '', '', '', '0.00']], $rows);
    }

    public function test_the_range_is_whole_days_in_dc_time(): void
    {
        $this->seedOnAFixedDate(); // Seeded history ends in October, so the test week is empty.
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow('2026-11-14 12:00');
        $employee = $this->employer()->employees()->orderBy('id')->firstOrFail();
        // DC's clocks go back at 2am on Nov 1, 2026: Nov 1 starts at 04:00 UTC (EDT), Nov 8 at 05:00 UTC (EST).
        $this->giveAt($employee, 1100, '2026-11-01 03:59:59'); // Oct 31, 23:59:59 in DC: out
        $this->giveAt($employee, 2200, '2026-11-01 04:00:00'); // Nov 1, 00:00 in DC: in
        $this->giveAt($employee, 3300, '2026-11-08 04:59:59'); // Nov 7, 23:59:59 in DC: in (last day)
        $this->giveAt($employee, 4400, '2026-11-08 05:00:00'); // Nov 8, 00:00 in DC: out
        $this->signIn(Role::EmployerAdmin);

        $rows = $this->csv($this->get(route('employer.payroll.csv', ['from' => '2026-11-01', 'to' => '2026-11-07']))->assertOk());
        $mine = array_values(array_filter($rows, fn ($r) => $r[1] === $employee->publicNumber()));

        $this->assertSame([
            [$employee->initials(), $employee->publicNumber(), '2026-11-01', Occasion::Recognition->label(), '22.00'],
            [$employee->initials(), $employee->publicNumber(), '2026-11-07', Occasion::Recognition->label(), '33.00'],
            ['Total for '.$employee->initials(), $employee->publicNumber(), '', '', '55.00'],
        ], $mine);
        $this->assertSame('55.00', end($rows)[4], 'Nobody else was given credits in that week');
    }

    public function test_another_employers_employees_never_appear(): void
    {
        $this->seed(DatabaseSeeder::class);
        $other = Employer::create(['bid_id' => $this->employer()->bid_id, 'name' => 'Second Test Employer', 'monthly_allowance_cents' => 10000, 'staff_count' => 10]);
        $theirs = Employee::create(['employer_id' => $other->id, 'display_name' => 'Fictional Outsider']);
        $this->giveAt($theirs, 7700, '2026-11-03 15:00:00');
        $this->signIn(Role::EmployerAdmin);

        $rows = $this->csv($this->get(route('employer.payroll.csv', ['from' => '2026-11-03', 'to' => '2026-11-03']))->assertOk());

        $this->assertSame([PayrollReportController::HEADERS, ['Grand total', '', '', '', '0.00']], $rows);
    }

    public function test_bad_ranges_go_back_to_the_form_with_an_error(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::EmployerAdmin);

        $this->get(route('employer.payroll.csv', ['from' => '2026-11-10', 'to' => '2026-11-09']))
            ->assertRedirect(route('employer.dashboard').'#payroll')
            ->assertSessionHasErrors(['to'], errorBag: PayrollReportController::ERRORS);
        $this->get(route('employer.payroll.csv', ['from' => 'yesterday', 'to' => '2026-11-09']))
            ->assertSessionHasErrors(['from'], errorBag: PayrollReportController::ERRORS);
    }

    public function test_only_the_employer_and_judges_can_download_it_and_nothing_is_written(): void
    {
        $this->seed(DatabaseSeeder::class);
        $counts = fn () => [DB::table('credit_allocations')->count(), DB::table('credit_ledger_entries')->count(), DB::table('credit_purchases')->count()];
        $before = $counts();

        foreach ([Role::Employee, Role::Merchant, Role::BidAdmin] as $role) {
            $this->actingAs($this->signIn($role))->get(route('employer.payroll.csv'))->assertForbidden();
        }
        $this->actingAs($this->signIn(Role::Judge))->get(route('employer.payroll.csv'))->assertOk();
        $this->assertSame($before, $counts());

        $this->assertContains('throttle:30,1,employer.payroll.csv', app('router')->getRoutes()->getByName('employer.payroll.csv')->gatherMiddleware());
    }

    public function test_the_dashboard_has_the_form_with_dc_month_defaults(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 03:00', 'UTC')); // Still Nov 30 in DC
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::EmployerAdmin);

        $this->get('/employer')->assertOk()
            ->assertSee('action="'.route('employer.payroll.csv').'"', false)
            ->assertSee('value="2026-11-01"', false)->assertSee('value="2026-11-30"', false);
    }

    public function test_in_a_sandbox_it_reads_only_the_sandbox_and_writes_nothing_live(): void
    {
        $this->assertContains('employer.payroll.csv', SandboxRoutes::ALLOWED);
        $this->seed(DatabaseSeeder::class);
        $this->setUpSandboxes();
        // A live allocation the sandbox copy can't have: an employee that only exists live.
        $liveOnly = Employee::create(['employer_id' => $this->employer()->id, 'display_name' => 'Zed Quill']);
        $this->giveAt($liveOnly, 4321, now()->utc()->format('Y-m-d H:i:s'));

        $this->startSandbox();
        $employeeId = $this->insideSandbox(fn () => Employee::whereHas('user', fn ($q) => $q->where('email', 'employee@sandbox.invalid'))->value('id'));
        $this->sandboxPost(route('employer.purchases.store'), ['purchase_amount' => '500'])->assertSessionHasNoErrors();
        $this->sandboxPost(route('employer.allocations.store'), ['mode' => 'individual', 'occasion' => 'recognition', 'amount' => '87.65', 'employee_id' => $employeeId])
            ->assertSessionHasNoErrors();

        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if ($query->connectionName !== Sandbox::CONNECTION && ! preg_match('/^\s*select\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $liveCounts = fn () => [DB::table('credit_allocations')->count(), DB::table('credit_ledger_entries')->count()];
        $before = $liveCounts();

        $today = now()->setTimezone('America/New_York')->format('Y-m-d');
        $response = $this->sandboxGet(route('employer.payroll.csv', ['from' => $today, 'to' => $today]))->assertOk();
        $rows = $this->csv($response);

        $sandboxGiven = $this->insideSandbox(fn () => (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_ALLOWANCE)
            ->where('created_at', '>=', Carbon::parse($today, 'America/New_York')->startOfDay()->utc())->sum('amount_cents'));
        $this->assertGreaterThanOrEqual(8765, $sandboxGiven);
        $this->assertSame(number_format($sandboxGiven / 100, 2, '.', ''), end($rows)[4], 'The grand total is the sandbox ledger\'s');
        $this->assertNotEmpty(array_filter($rows, fn ($r) => $r[4] === '87.65'), 'The credits given in the sandbox are in it');
        $this->assertEmpty(array_filter($rows, fn ($r) => $r[0] === 'Z.Q.' || $r[4] === '43.21'), 'Nothing from the live database');
        $this->assertSame([], $writes, 'The sandbox download wrote to the live database');
        $this->assertSame($before, $liveCounts());
    }
}
