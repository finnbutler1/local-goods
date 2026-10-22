<?php

namespace Tests\Feature;

use App\Enums\Occasion;
use App\Enums\Role;
use App\Models\CreditAllocation;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Services\CreditLedger;
use App\Services\DemoResetter;
use App\Services\EmployerMetrics;
use App\Services\EmployerProgram;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The employer/HR dashboard on the seeded demo: program setup, buying
 * credits (pending until "Pay now"), and giving them to one person or many.
 * All amounts are integer cents and every balance comes from the ledger.
 */
class EmployerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Employer $employer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->employer = Employer::firstOrFail();
        $this->signIn(Role::EmployerAdmin);
    }

    /** Buys and pays for credits through the dashboard; returns the purchase. */
    private function fund(string $dollars): CreditPurchase
    {
        // Simulated mode: one step, paid at once.
        $this->post('/employer/purchases', ['purchase_amount' => $dollars])->assertRedirect();

        return CreditPurchase::latest('id')->firstOrFail();
    }

    public function test_the_dashboard_shows_metrics_credits_and_program(): void
    {
        $this->get('/employer')->assertOk()
            ->assertSee('Your team got out into the neighborhood.')
            ->assertSee('Employees who got out')
            ->assertSee('How we count this')
            ->assertSee('Visits per week, last 8 weeks')
            ->assertSee('Buy credits')
            ->assertSee("Stripe isn't connected on this server, so buying is simulated: the credits are added straight away with no real money.", false)
            ->assertSee('Buy credits (simulated, no real money)')
            ->assertDontSee('Request purchase')
            ->assertSee('Simulated')
            ->assertSee('Program setup')
            ->assertSee(Employee::orderBy('id')->firstOrFail()->publicLabel())
            ->assertDontSee('Coming in a later build');
    }

    public function test_buying_in_simulated_mode_is_one_step_with_one_ledger_entry(): void
    {
        $balance = $this->employer->balanceCents();

        $this->post('/employer/purchases', ['purchase_amount' => '6000.50'])
            ->assertRedirect(route('employer.dashboard').'#credits')->assertSessionHas('status');

        $purchase = CreditPurchase::latest('id')->firstOrFail();
        $this->assertSame([600050, CreditPurchase::STATUS_SIMULATED], [$purchase->amount_cents, $purchase->status]);
        $this->assertSame(1, CreditLedgerEntry::where('credit_purchase_id', $purchase->id)->count());
        $this->assertSame($balance + 600050, $this->employer->balanceCents());
        $this->get('/employer')->assertSee('$6,000.50')->assertSee('Paid (simulated)');

        $this->post("/employer/purchases/{$purchase->id}/pay")->assertSessionHas('error', 'Nothing changed: That purchase is already paid.');
        $this->assertSame(1, CreditLedgerEntry::where('credit_purchase_id', $purchase->id)->count());
        $this->assertSame($balance + 600050, $this->employer->balanceCents());
    }

    public function test_an_older_pending_purchase_can_still_be_paid_simulated(): void
    {
        $purchase = app(CreditLedger::class)->requestPurchase($this->employer, 25000, 'MPG-OLD-1');
        $balance = $this->employer->balanceCents();

        $this->get('/employer')->assertSee('Pay now (simulated, no real money)');
        $this->post("/employer/purchases/{$purchase->id}/pay")->assertSessionHas('status');

        $this->assertSame(1, CreditLedgerEntry::where('credit_purchase_id', $purchase->id)->count());
        $this->assertSame($balance + 25000, $this->employer->balanceCents());
    }

    public function test_pending_credits_cannot_be_given_out(): void
    {
        app(CreditLedger::class)->requestPurchase($this->employer, 1000000, 'MPG-PENDING-1');
        $employee = Employee::orderBy('id')->firstOrFail();
        $before = [$this->employer->balanceCents(), $employee->balanceCents(), CreditAllocation::count()];

        $this->post('/employer/allocations', ['mode' => 'individual', 'occasion' => 'recognition', 'employee_id' => $employee->id, 'amount' => '500'])
            ->assertSessionHasErrors(['amount' => 'That needs $500.00 but only '.Money::format($before[0]).' of paid credits is available. Buy more credits first.']);

        $this->assertSame($before, [$this->employer->balanceCents(), $employee->balanceCents(), CreditAllocation::count()]);
    }

    public function test_giving_one_person_credits_moves_exact_cents(): void
    {
        $this->fund('100');
        $employee = Employee::where('display_name', DemoHistorySeeder::NAMES[5])->sole();
        [$employerBefore, $employeeBefore] = [$this->employer->balanceCents(), $employee->balanceCents()];

        $this->post('/employer/allocations', ['mode' => 'individual', 'occasion' => 'birthday', 'employee_id' => $employee->id, 'amount' => '25.05'])
            ->assertSessionHas('status', '$25.05 birthday credit sent to '.$employee->initials().' They can spend it now.');

        $this->assertSame($employerBefore - 2505, $this->employer->balanceCents());
        $this->assertSame($employeeBefore + 2505, $employee->balanceCents());
        $this->assertSame(Occasion::Birthday, CreditAllocation::latest('id')->first()->occasion);
    }

    public function test_bulk_to_everyone_and_to_ticked_people(): void
    {
        $this->employer->update(['monthly_budget_cents' => null]);
        $this->fund('1000');
        $all = Employee::orderBy('id')->get();
        $before = $all->mapWithKeys(fn ($e) => [$e->id => $e->balanceCents()]);
        $employerBefore = $this->employer->balanceCents();

        $this->post('/employer/allocations', ['mode' => 'bulk', 'recipients' => 'all', 'occasion' => 'recognition', 'amount' => '10'])
            ->assertSessionHas('status', '$10.00 recognition credit sent to each of 40 employees ($400.00 in all). They can spend it now.');
        $this->assertSame($employerBefore - 40 * 1000, $this->employer->balanceCents());

        $ticked = $all->take(3)->pluck('id')->all();
        $this->post('/employer/allocations', ['mode' => 'bulk', 'recipients' => 'selected', 'employee_ids' => $ticked, 'occasion' => 'welcome', 'amount' => '50'])
            ->assertSessionHas('status');

        foreach ($all as $employee) {
            $extra = 1000 + (in_array($employee->id, $ticked, true) ? 5000 : 0);
            $this->assertSame($before[$employee->id] + $extra, $employee->balanceCents());
        }
        $this->assertSame($employerBefore - 40 * 1000 - 3 * 5000, $this->employer->balanceCents());
    }

    public function test_bulk_beyond_the_monthly_budget_writes_nothing(): void
    {
        $this->fund('10000');
        $count = CreditAllocation::count();
        // Seeded budget $7,500; normally $6,000 of allowances are already given this month.
        $this->employer->update(['monthly_budget_cents' => EmployerProgram::allocatedInMonthCents($this->employer) + 150000]);

        $this->post('/employer/allocations', ['mode' => 'bulk', 'recipients' => 'all', 'occasion' => 'welcome', 'amount' => '50'])
            ->assertSessionHasErrors(['amount' => 'That needs $2,000.00 but only $1,500.00 is left in this month\'s budget.']);

        $this->assertSame($count, CreditAllocation::count());
    }

    public function test_ticking_nobody_or_someone_from_another_employer_is_refused(): void
    {
        $this->post('/employer/allocations', ['mode' => 'bulk', 'recipients' => 'selected', 'occasion' => 'monthly', 'amount' => '5'])
            ->assertSessionHasErrors('employee_ids');

        $outsider = Employer::create(['bid_id' => $this->employer->bid_id, 'name' => 'Other Co', 'monthly_allowance_cents' => 100]);
        $stranger = Employee::create(['employer_id' => $outsider->id, 'display_name' => 'Somebody Else']);
        $this->post('/employer/allocations', ['mode' => 'individual', 'occasion' => 'monthly', 'employee_id' => $stranger->id, 'amount' => '5'])
            ->assertSessionHasErrors('employee_id');
        $this->assertSame(0, $stranger->balanceCents());
    }

    public function test_bad_amounts_get_clear_messages(): void
    {
        $this->post('/employer/purchases', ['purchase_amount' => '12.345'])
            ->assertSessionHasErrors(['purchase_amount' => 'Enter an amount in dollars, like 150 or 12.50.']);
        $this->post('/employer/purchases', ['purchase_amount' => '0'])
            ->assertSessionHasErrors(['purchase_amount' => 'Enter an amount greater than zero.']);
        $this->assertSame(0, CreditPurchase::where('status', CreditPurchase::STATUS_PENDING)->count());
    }

    public function test_program_setup_saves_cents_and_switches_occasions(): void
    {
        $this->post('/employer/program', [
            'monthly_budget' => '8000.25',
            'monthly_allowance' => '175',
            'occasions' => [
                'welcome' => ['enabled' => '1', 'amount' => '75.50'],
                'birthday' => ['enabled' => '0', 'amount' => '25'],
                'recognition' => ['enabled' => '1', 'amount' => '100'],
            ],
        ])->assertSessionHasNoErrors()->assertSessionHas('status', "Program saved. These amounts now fill in the Give credits form, and the monthly budget is checked on every gift from now on. Credits already given don't change.");

        $e = $this->employer->fresh();
        $this->assertSame([800025, 17500, 7550, null, 10000], [$e->monthly_budget_cents, $e->monthly_allowance_cents, $e->welcome_cents, $e->birthday_cents, $e->recognition_cents]);
        $this->assertSame([Occasion::Monthly, Occasion::Welcome, Occasion::Recognition], $e->activeOccasions());
        $this->get('/employer')->assertSee('Recognition award ($100.00)')->assertDontSee('Birthday ($');
    }

    public function test_program_setup_needs_an_amount_for_a_switched_on_occasion(): void
    {
        $this->post('/employer/program', [
            'monthly_allowance' => '150',
            'occasions' => ['welcome' => ['enabled' => '1', 'amount' => '']],
        ])->assertSessionHasErrors(['occasions.welcome.amount' => 'Enter an amount, or switch this occasion off.']);
    }

    public function test_other_roles_cannot_open_or_post(): void
    {
        foreach ([Role::Employee, Role::Merchant, Role::BidAdmin] as $role) {
            $this->signIn($role);
            $this->get('/employer')->assertForbidden();
            $this->post('/employer/purchases', ['purchase_amount' => '10'])->assertForbidden();
        }
        $this->assertSame(0, CreditPurchase::where('status', CreditPurchase::STATUS_PENDING)->count());
    }

    public function test_an_empty_state_when_no_employer_exists(): void
    {
        foreach (DemoResetter::DEMO_TABLES as $table) {
            DB::table($table)->delete();
        }

        $this->get('/employer')->assertOk()->assertSee('No employer program is set up yet');
    }

    public function test_recently_given_shows_the_occasion_not_monthly_allowance(): void
    {
        $this->fund('100');
        $employee = Employee::orderBy('id')->firstOrFail();

        foreach (['welcome' => 'New-hire welcome', 'birthday' => 'Birthday', 'recognition' => 'Recognition award'] as $occasion => $label) {
            $this->post('/employer/allocations', ['mode' => 'individual', 'occasion' => $occasion, 'employee_id' => $employee->id, 'amount' => '1']);
            $this->travel(1)->seconds();
        }

        $section = $this->recentlyGiven();
        $this->assertStringContainsString('New-hire welcome', $section);
        $this->assertStringContainsString('Birthday', $section);
        $this->assertStringContainsString('Recognition award', $section);
        // The seeded monthly allowance is older, so it comes after all three.
        $this->assertLessThan(strpos($section, 'Monthly allowance'), strpos($section, 'New-hire welcome'));
    }

    public function test_a_bulk_allocation_is_one_row_that_expands_to_each_person(): void
    {
        $this->employer->update(['monthly_budget_cents' => null]);
        $this->fund('1000');

        $this->post('/employer/allocations', ['mode' => 'bulk', 'recipients' => 'all', 'occasion' => 'welcome', 'amount' => '25']);

        $section = $this->recentlyGiven();
        $this->assertSame(1, substr_count($section, 'Welcome · 40 employees'));
        $this->assertStringContainsString('$1,000.00', $section);
        $this->assertStringContainsString('$25.00 each', $section);
        foreach (Employee::orderBy('id')->get() as $employee) {
            $this->assertStringContainsString(e($employee->publicLabel()), $section);
        }
    }

    public function test_the_participation_tiles_lead_with_counts(): void
    {
        $m = app(EmployerMetrics::class)->for($this->employer);

        $this->get('/employer')
            ->assertSeeInOrder([$m['redeemers'], 'employees got out', $m['participation_pct'].'% of enrolled · '.$m['staff_pct'].'% of all 120 staff'])
            ->assertSeeInOrder([$m['businesses'].' of 8', 'participating businesses', $m['first_visits'].' first-time']);
    }

    /** The "Recently given" card's HTML. */
    private function recentlyGiven(): string
    {
        $html = $this->get('/employer')->assertOk()->getContent();
        $start = strpos($html, 'id="allocations-heading"');

        return substr($html, $start, strpos($html, 'id="program"') - $start);
    }
}
