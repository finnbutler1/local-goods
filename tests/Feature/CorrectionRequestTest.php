<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCorrections;
use Tests\TestCase;

/**
 * "Request a correction" (ADR 0028): only your own items, at most one open
 * request per item (the database's unique open_key), rate-limited, and
 * asking moves no money. Judges see the screens read-only and can't ask.
 */
class CorrectionRequestTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->withoutSeededCorrections();
    }

    private function clearedFor(Employee $employee): Redemption
    {
        return $employee->redemptions()->where('status', Redemption::STATUS_APPROVED)->latest('id')->firstOrFail();
    }

    public function test_an_employee_asks_about_their_own_cleared_redemption_and_no_money_moves(): void
    {
        $user = $this->signIn(Role::Employee);
        $redemption = $this->clearedFor($user->employee);
        $ledger = CreditLedgerEntry::count();
        $balance = $user->employee->balanceCents();

        $this->post(route('employee.correction.store', $redemption), ['reason' => 'wrong_amount', 'note' => '  Charged for two lattes, had one.  '])
            ->assertRedirect(route('employee.wallet').'#recent-heading')
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Refund requested') && str_contains($s, $redemption->merchant->name));

        $correction = Correction::sole();
        $this->assertSame(Correction::TYPE_REDEMPTION_REFUND, $correction->type);
        $this->assertSame(Correction::STATUS_REQUESTED, $correction->status);
        $this->assertSame('redemption:'.$redemption->id, $correction->open_key);
        $this->assertSame('Charged for two lattes, had one.', $correction->note);
        $this->assertSame([$redemption->employee_id, $redemption->merchant_id, 'employee'], [$correction->employee_id, $correction->merchant_id, $correction->requested_by_role]);
        $this->assertNull($correction->amount_cents);
        $this->assertSame($ledger, CreditLedgerEntry::count());
        $this->assertSame($balance, $user->employee->balanceCents());

        $this->get(route('employee.wallet'))->assertOk()
            ->assertSee('data-correction-status="requested"', false)
            ->assertSee('Redemption refund: Requested');
    }

    public function test_someone_elses_redemption_is_a_404(): void
    {
        $this->signIn(Role::Employee);
        $other = Employee::where('user_id', null)->has('redemptions')->firstOrFail();

        $this->post(route('employee.correction.store', $this->clearedFor($other)), ['reason' => 'other'])->assertNotFound();

        $this->signIn(Role::Merchant);
        $elsewhere = Redemption::where('merchant_id', '!=', auth()->user()->merchant->id)->where('status', Redemption::STATUS_APPROVED)->firstOrFail();
        $this->post(route('merchant.correction.redemption', $elsewhere), ['reason' => 'other'])->assertNotFound();
        $this->assertSame(0, Correction::count());
    }

    public function test_a_held_redemption_has_no_refund_request(): void
    {
        $user = $this->signIn(Role::Employee);
        $held = Redemption::create([
            'employee_id' => $user->employee->id, 'merchant_id' => Merchant::firstOrFail()->id, 'amount_cents' => 900,
            'status' => Redemption::STATUS_UNDER_REVIEW, 'scored_by' => 'test', 'signals' => [],
        ]);

        $this->post(route('employee.correction.store', $held), ['reason' => 'other'])
            ->assertSessionHas('error', 'Only a cleared redemption can be refunded. A held one is reviewed instead.');
        $this->assertSame(0, Correction::count());
    }

    public function test_one_open_request_per_item_even_between_the_employee_and_the_merchant(): void
    {
        $merchant = $this->signIn(Role::Merchant)->merchant;
        $redemption = Redemption::where('merchant_id', $merchant->id)->where('status', Redemption::STATUS_APPROVED)->latest('id')->firstOrFail();

        $this->post(route('merchant.correction.redemption', $redemption), ['reason' => 'wrong_items'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, $redemption->employee->initials()));
        $this->post(route('merchant.correction.redemption', $redemption), ['reason' => 'duplicate'])
            ->assertSessionHas('error', 'There\'s already an open request for this. Its status shows here.');

        $this->actingAs($employeeUser = User::factory()->role(Role::Employee)->create());
        $redemption->employee->update(['user_id' => $employeeUser->id]);
        $this->post(route('employee.correction.store', $redemption), ['reason' => 'wrong_amount'])
            ->assertSessionHas('error', 'There\'s already an open request for this. Its status shows here.');

        $this->assertSame(1, Correction::count());
        $this->assertSame('merchant', Correction::sole()->requested_by_role);
    }

    public function test_after_a_rejection_the_item_may_be_asked_about_again(): void
    {
        $user = $this->signIn(Role::Employee);
        $redemption = $this->clearedFor($user->employee);
        $this->post(route('employee.correction.store', $redemption), ['reason' => 'other']);
        Correction::sole()->update(['status' => Correction::STATUS_REJECTED, 'open_key' => null, 'decision_reason' => 'The receipt matches the order.']);

        $this->get(route('employee.wallet'))->assertSee('Not approved')->assertSee('“The receipt matches the order.”', false);

        $this->post(route('employee.correction.store', $redemption), ['reason' => 'wrong_amount'])->assertSessionHas('status');
        $this->assertSame(2, Correction::count());
    }

    public function test_a_merchant_asks_about_a_stripe_payout_but_not_a_simulated_one(): void
    {
        $merchant = $this->signIn(Role::Merchant)->merchant;
        $simulated = $merchant->payouts()->where('status', MerchantPayout::STATUS_SIMULATED)->firstOrFail();
        $stripe = MerchantPayout::create(['merchant_id' => $merchant->id, 'amount_cents' => 1250, 'status' => MerchantPayout::STATUS_FAILED, 'failure_reason' => 'Stripe refused the request (example).']);

        $this->post(route('merchant.correction.payout', $simulated), ['reason' => 'not_received'])
            ->assertSessionHas('error', 'Simulated payouts move no real money, so there is nothing to send.');
        $this->post(route('merchant.correction.payout', $stripe), ['reason' => 'not_received'])
            ->assertRedirect(route('merchant.dashboard').'#payouts-heading')->assertSessionHas('status');

        $this->assertSame(['payout:'.$stripe->id, Correction::TYPE_MERCHANT_ADJUSTMENT], [Correction::sole()->open_key, Correction::sole()->type]);
        $this->get(route('merchant.dashboard'))->assertSee('Merchant adjustment: Requested');
    }

    public function test_the_employer_asks_for_unspent_credits_back(): void
    {
        $this->signIn(Role::EmployerAdmin);

        $this->get(route('employer.dashboard'))->assertOk()->assertSee('Refund unspent credits');
        $this->post(route('employer.correction.store'), ['reason' => 'bought_too_much'])
            ->assertRedirect(route('employer.dashboard').'#refund-credits')->assertSessionHas('status');

        $this->assertSame('employer:'.Employer::firstOrFail()->id, Correction::sole()->open_key);
        $this->get(route('employer.dashboard'))->assertSee('Refund of unspent credits: Requested');
    }

    public function test_reasons_must_come_from_the_list_and_notes_stay_short(): void
    {
        $user = $this->signIn(Role::Employee);
        $redemption = $this->clearedFor($user->employee);

        $this->post(route('employee.correction.store', $redemption), ['reason' => 'bought_too_much', 'correction_for' => 'redemption:'.$redemption->id])
            ->assertSessionHasErrorsIn('correction', ['reason' => 'Choose what went wrong.']);
        $this->post(route('employee.correction.store', $redemption), ['reason' => 'other', 'note' => str_repeat('a', 501)])
            ->assertSessionHasErrorsIn('correction', ['note']);
        $this->assertSame(0, Correction::count());
    }

    public function test_requests_are_rate_limited(): void
    {
        $user = $this->signIn(Role::Employee);
        $redemption = $this->clearedFor($user->employee);

        foreach (range(1, 5) as $i) {
            $this->post(route('employee.correction.store', $redemption), ['reason' => 'nope'])->assertRedirect();
        }

        $this->post(route('employee.correction.store', $redemption), ['reason' => 'other'])->assertTooManyRequests();
        $this->assertSame(0, Correction::count());
    }

    public function test_judges_see_the_request_screens_read_only_and_cannot_ask(): void
    {
        $this->signIn(Role::Judge);
        $redemption = Redemption::where('status', Redemption::STATUS_APPROVED)->firstOrFail();

        $this->get(route('employee.wallet'))->assertOk()->assertSee('Read-only: judges can\'t send requests.', false)
            ->assertDontSee('action="'.route('employee.correction.store', $redemption).'"', false);
        $this->get(route('merchant.dashboard'))->assertOk()->assertSee('Customer was charged wrongly');

        $this->post(route('employee.correction.store', $redemption), ['reason' => 'other'])->assertForbidden();
        $this->post(route('employer.correction.store'), ['reason' => 'other'])->assertForbidden();
        $this->assertSame(0, Correction::count());
    }

    public function test_other_roles_cannot_ask(): void
    {
        $this->signIn(Role::BidAdmin);
        $redemption = Redemption::where('status', Redemption::STATUS_APPROVED)->firstOrFail();

        $this->post(route('employee.correction.store', $redemption), ['reason' => 'other'])->assertForbidden();
        $this->post(route('employer.correction.store'), ['reason' => 'other'])->assertForbidden();
        $this->assertSame(0, Correction::count());
    }
}
