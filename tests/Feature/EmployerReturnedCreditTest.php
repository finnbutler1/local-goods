<?php

namespace Tests\Feature;

use App\Enums\DenialReason;
use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
use App\Services\Payments\StripeOnboarding;
use App\Services\RedemptionReviewService;
use App\Services\RedemptionService;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Credit a BID admin sent back to the employer by denying a held redemption
 * as suspected abuse (ADR 0016): visible on the employer's Credits page, and
 * still Stripe-funded when it is given out and spent again.
 */
class EmployerReturnedCreditTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private Employer $employer;

    private Merchant $grind;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->employer = Employer::firstOrFail();
        $this->grind = Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail();
        $this->admin = User::factory()->role(Role::BidAdmin)->create(['name' => 'Sam Taylor']);
        // Room to hand out the amounts below.
        app(CreditLedger::class)->purchase($this->employer, 100000, 'MPG-TEST-RET');
    }

    private function heldRedemption(string $name, int $cents): Redemption
    {
        $employee = Employee::where('display_name', $name)->firstOrFail();
        app(CreditLedger::class)->allocate($this->employer, $employee, $cents);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(88, 'hold', 'Round amount far above a café ticket.'));

        return app(RedemptionService::class)->redeem($employee->fresh(), $this->grind, $cents);
    }

    private function deny(Redemption $redemption, DenialReason $why): void
    {
        app(RedemptionReviewService::class)->deny($redemption, $this->admin, 'Same round amount every week.', $why);
    }

    public function test_the_credits_page_shows_returned_credit_and_the_totals_add_up(): void
    {
        $first = $this->heldRedemption('Alex Moreau', 30000);
        $second = $this->heldRedemption('Taylor Brennan', 22500);
        $mistake = $this->heldRedemption('Riley Nakamura', 4000);
        $before = $this->employer->balanceCents();

        $this->deny($first, DenialReason::SuspectedAbuse);
        $this->deny($second, DenialReason::SuspectedAbuse);
        $this->deny($mistake, DenialReason::NoPurchase);

        $balance = $this->employer->balanceCents();
        $this->assertSame($before + 52500, $balance, 'Only suspected-abuse denials come back to the employer');
        $this->assertSame(
            (int) CreditPurchase::whereIn('status', CreditPurchase::PAID_STATUSES)->sum('amount_cents')
                - (int) CreditLedgerEntry::where('employer_id', $this->employer->id)->where('type', CreditLedgerEntry::TYPE_ALLOCATION_OUT)->sum('amount_cents') * -1
                + 52500,
            $balance,
            'Ready to give = paid purchases − given out + returned',
        );

        $this->signIn(Role::EmployerAdmin);
        $page = $this->get('/employer')->assertOk()
            ->assertSee(Money::format($balance))
            ->assertSee('Includes $525.00 returned from 2 denied redemptions.')
            ->assertSee('data-returned-credit="52500"', false)
            ->assertSee('Purchases and returned credit')
            ->assertSee('data-returned-entry="'.$first->id.'"', false)
            ->assertSee('data-returned-entry="'.$second->id.'"', false)
            ->assertDontSee('data-returned-entry="'.$mistake->id.'"', false)
            ->assertSee('+$300.00')
            ->assertSee('+$225.00')
            ->assertSee("Denied redemption #{$first->id} · suspected abuse · decided by BID admin S.T.")
            ->assertSee('A.M.')
            ->assertSee('T.B.');

        foreach (['Alex Moreau', 'Taylor Brennan', 'Sam Taylor'] as $name) {
            $page->assertDontSee($name);
        }
    }

    public function test_no_returned_line_when_nothing_was_denied_to_the_employer(): void
    {
        $this->deny($this->heldRedemption('Alex Moreau', 4000), DenialReason::NoPurchase);
        $this->signIn(Role::EmployerAdmin);

        $this->get('/employer')->assertOk()
            ->assertDontSee('returned from')
            ->assertDontSee('data-returned-credit', false)
            ->assertDontSee('Purchases and returned credit');
    }

    public function test_returned_credit_stays_stripe_funded_when_given_out_and_spent_again(): void
    {
        $this->fakeStripe();
        $ledger = app(CreditLedger::class);
        $purchase = $ledger->requestPurchase($this->employer, 5000, 'MPG-STRIPE-RET');
        $purchase->update(['payment_token' => 'tok-ret']);
        $ledger->markPurchasePaidByStripe($purchase, 'pi_test_ret', 'ch_test_ret', 5000, 'usd');
        $this->stripe->accounts['acct_test_FG'] = ['id' => 'acct_test_FG'];
        app(StripeOnboarding::class)->apply($this->grind, $this->stripe->completeOnboarding('acct_test_FG'));

        // Held, then denied: no payout row, so the Stripe payment's money is still unclaimed.
        $this->deny($this->heldRedemption('Alex Moreau', 4000), DenialReason::SuspectedAbuse);
        $this->assertSame(5000, $purchase->fresh()->unclaimedCents());
        $this->assertSame([], $this->stripe->callsOf('transfer'));

        // Given out again and spent at a merchant: it transfers through Stripe from the same payment.
        $other = Employee::where('display_name', 'Taylor Brennan')->firstOrFail();
        $ledger->allocate($this->employer, $other, 4000);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));
        $redemption = app(RedemptionService::class)->redeem($other->fresh(), $this->grind, 3837);

        $payout = MerchantPayout::where('redemption_id', $redemption->id)->sole();
        $this->assertSame(MerchantPayout::STATUS_SENT, $payout->status);
        $this->assertSame($purchase->id, $payout->credit_purchase_id);
        [$transfer] = $this->stripe->callsOf('transfer');
        $this->assertSame(['ch_test_ret', 'MPG-STRIPE-RET', 3837], [$transfer['source_transaction'], $transfer['transfer_group'], $transfer['amount']]);
        $this->assertSame(5000 - 3837, $purchase->fresh()->unclaimedCents());

        $this->stripe->paymentIntents = [[
            'id' => 'pi_test_ret', 'status' => 'succeeded', 'amount' => 5000, 'amount_received' => 5000, 'currency' => 'usd',
            'metadata' => ['lg_purchase_token' => 'tok-ret'],
        ]];
        $this->stripe->balance = ['available' => [['amount' => 5000 - 3837, 'currency' => 'usd']], 'pending' => [['amount' => 0, 'currency' => 'usd']]];
        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/reconciliation')->assertOk()->assertSee('data-reconciled="yes"', false);

        $purchased = (int) CreditPurchase::whereIn('status', CreditPurchase::PAID_STATUSES)->sum('amount_cents');
        $paidOut = (int) MerchantPayout::paid()->sum('amount_cents');
        $held = (int) Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->sum('amount_cents');
        $this->assertSame($purchased - $paidOut - $held, (int) CreditLedgerEntry::sum('amount_cents'));
    }
}
