<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\SolvencyCheckRun;
use App\Models\User;
use App\Services\Solvency\SolvencyCheck;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\TestCase;

/**
 * Where the money is (ADR 0033): the two tranches and the solvency check,
 * for judges, the BID admin and the platform admin. Judges see customer
 * funds and the check, never an operating funds figure; only the platform
 * admin runs the check by hand.
 */
class WhereTheMoneyIsPageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOnAFixedDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_reviewers_and_admins_can_open_it_and_no_one_else(): void
    {
        $this->get('/where-the-money-is')->assertRedirect('/login');

        foreach ([Role::Judge, Role::BidAdmin, Role::PlatformAdmin] as $role) {
            $this->actingAs($this->signIn($role))->get('/where-the-money-is')->assertOk()->assertSee('Where the money is');
        }
        foreach ([Role::Employee, Role::EmployerAdmin, Role::Merchant] as $role) {
            $this->actingAs($this->signIn($role))->get('/where-the-money-is')->assertForbidden();
        }

        $contact = User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::PersonalContact]);
        $this->actingAs($contact)->get('/where-the-money-is')->assertForbidden();
    }

    public function test_it_shows_the_two_tranches_and_says_unspent_credits_stay_the_employers(): void
    {
        $now = app(SolvencyCheck::class)->now();
        $html = $this->actingAs($this->signIn(Role::PlatformAdmin))->get('/where-the-money-is')->assertOk();

        $html->assertSee(Money::format($now->customerFundsCents()))
            ->assertSee(Money::format($now->employerCents))
            ->assertSee(Money::format($now->employeeCents))
            ->assertSeeText('Unspent credits are customer funds held for the program, never Local Goods\' money.')
            ->assertSeeText('can be refunded on request; credits given to an employee stay in their wallet, with no expiry.')
            ->assertSee('data-operating-funds>$0.00', false)
            ->assertSee('data-in-hand>'.Money::format($now->inHandCents()), false)
            ->assertSee('Demo data: all people and transactions are fictional.')
            // ADR 0034: two separate Stripe balances, and still not a bank FBO or trust account.
            ->assertSee('Two separate Stripe balances, still not a bank trust account.')
            ->assertSee('the customer funds account is not a bank FBO')
            ->assertSee('needs legal advice, which we have not had.');
    }

    public function test_judges_see_customer_funds_and_the_check_but_never_operating_funds_or_money_in_hand(): void
    {
        $this->artisan('ledger:check-solvency');
        $html = $this->actingAs($this->signIn(Role::Judge))->get('/where-the-money-is')->assertOk();

        $html->assertSee(Money::format(app(SolvencyCheck::class)->now()->customerFundsCents()))
            ->assertSee('data-operating-funds="hidden"', false)
            ->assertSee('Shown to Local Goods staff only.')
            ->assertDontSee('data-in-hand', false)
            ->assertDontSee('platform fees')
            ->assertSee('data-solvency="pass"', false)
            ->assertDontSee('Run the check now')
            ->assertSee('only the platform admin can run the check by hand');

        $this->actingAs($this->signIn(Role::Judge))->post('/admin/solvency-check')->assertForbidden();
        $this->actingAs($this->signIn(Role::BidAdmin))->post('/admin/solvency-check')->assertForbidden();
        $this->assertSame(1, SolvencyCheckRun::count(), 'Neither ran it');
    }

    public function test_before_any_run_it_says_so(): void
    {
        $this->actingAs($this->signIn(Role::BidAdmin))->get('/where-the-money-is')
            ->assertSee('data-solvency="not-run"', false)
            ->assertSee('Not run yet.')
            ->assertSee('data-solvency-now="pass"', false);
    }

    public function test_the_platform_admin_runs_it_and_sees_when_how_many_entries_and_over_what_dates(): void
    {
        $admin = $this->signIn(Role::PlatformAdmin);

        $this->actingAs($admin)->post('/admin/solvency-check')
            ->assertRedirect(route('money').'#solvency-check')
            ->assertSessionHas('status', fn (string $s) => str_starts_with($s, 'Solvency check passed: '.number_format(CreditLedgerEntry::count()).' ledger entries'));

        $run = SolvencyCheckRun::latest();
        $this->assertSame(SolvencyCheckRun::TRIGGER_ADMIN, $run->trigger);
        $this->actingAs($admin)->get('/where-the-money-is')
            ->assertSee('data-solvency="pass"', false)
            ->assertSee('By the platform admin')
            ->assertSee(number_format($run->entries_checked).' ledger entries')
            ->assertSee(number_format($run->moments_checked).' moments')
            ->assertSee($run->first_entry_at->setTimezone('America/New_York')->format('M j, Y').' to '.$run->last_entry_at->setTimezone('America/New_York')->format('M j, Y'));
    }

    public function test_a_failed_check_shows_when_by_how_much_and_who_was_below_zero(): void
    {
        // An overspend: the employee pays $11.00 more than their balance and the merchant is owed all of it.
        $employee = Employee::orderBy('id')->skip(6)->firstOrFail();
        $spent = $employee->balanceCents() + 1100;
        $at = now()->utc()->format('Y-m-d H:i:s');
        DB::table('credit_ledger_entries')->insert([
            ['employee_id' => $employee->id, 'merchant_id' => null, 'amount_cents' => -$spent, 'type' => CreditLedgerEntry::TYPE_REDEMPTION, 'description' => 'Planted by a test', 'created_at' => $at],
            // The first merchant's real id, not 1: on MySQL ids keep counting between tests.
            ['employee_id' => null, 'merchant_id' => Merchant::orderBy('id')->value('id'), 'amount_cents' => $spent, 'type' => CreditLedgerEntry::TYPE_MERCHANT_EARNED, 'description' => 'Planted by a test', 'created_at' => $at],
        ]);

        $this->actingAs($this->signIn(Role::PlatformAdmin))->post('/admin/solvency-check')->assertSessionHas('error');

        $html = $this->actingAs($this->signIn(Role::Judge))->get('/where-the-money-is')->assertOk();
        $html->assertSee('data-solvency="fail"', false)
            ->assertSee('From Oct 24, 2026, 12:00 PM to now')
            ->assertSee('short by $11.00')
            ->assertSee($employee->publicLabel().' (employee)')
            ->assertDontSee($employee->display_name)
            ->assertSee('data-solvency-now="fail"', false);
    }

    public function test_it_is_linked_from_reconciliation_and_the_admin_bid_admin_and_judge_homes(): void
    {
        $link = 'href="'.route('money').'"';
        $this->actingAs($this->signIn(Role::PlatformAdmin))->get('/admin/reconciliation')->assertSee($link, false);
        $this->actingAs($this->signIn(Role::PlatformAdmin))->get('/admin')->assertSee($link, false);
        $this->actingAs($this->signIn(Role::BidAdmin))->get('/bid-admin')->assertSee($link, false);
        $this->actingAs($this->signIn(Role::Judge))->get('/judge')->assertSee($link, false);
    }
}
