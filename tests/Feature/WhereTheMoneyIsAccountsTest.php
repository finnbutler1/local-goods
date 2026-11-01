<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\MerchantFeeChange;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Payments\AccountBalances;
use App\Services\Payments\OperatingAccountSetup;
use App\Services\Payments\OperatingSweeps;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\Support\AccessibilityAudit;
use Tests\TestCase;

/**
 * Where the money is with two accounts (ADR 0034): both real Stripe
 * balances side by side for staff, what each may be used for, the sweep
 * history, and where earned money came from, read from the ledger and the
 * fee audit log, without ever suggesting ongoing income. Judges see the
 * accounts in words and no money figures.
 */
class WhereTheMoneyIsAccountsTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_staff_see_both_accounts_and_their_real_stripe_balances_side_by_side(): void
    {
        $this->fakeStripe();
        app(OperatingAccountSetup::class)->create('127.0.0.1');
        $this->stripe->balance = ['available' => [['amount' => 123456, 'currency' => 'usd']], 'pending' => [['amount' => 44, 'currency' => 'usd']]];

        $this->signIn(Role::PlatformAdmin);
        $this->get(route('money'))->assertOk()
            ->assertSee('data-account="customer-funds"', false)->assertSee('data-account="operating"', false)
            ->assertSeeText('paying merchants for what staff spent, and refunding employers. Nothing else.')
            ->assertSeeText('only money Local Goods has earned, moved here from the customer funds account by a recorded sweep. Never customer money.')
            ->assertSee('$1,235.00')
            ->assertSeeText('in test mode Stripe takes its card fees from this balance')
            ->assertSee('data-operating-stripe-balance', false)->assertSee('$0.00')
            ->assertSee('data-earned-origin="none"', false)
            ->assertSeeText('Local Goods charges nothing: the platform fee and the merchant fee are both off, so it has earned nothing.')
            ->assertSee('data-sweep-history="empty"', false);
    }

    public function test_balances_are_read_once_a_minute_and_a_sweep_shows_at_once(): void
    {
        $this->fakeStripe();
        app(OperatingAccountSetup::class)->create('127.0.0.1');
        $this->signIn(Role::PlatformAdmin);

        $this->get(route('money'))->assertOk();
        $this->get(route('money'))->assertOk();
        $this->assertCount(1, $this->stripe->callsOf('balance'), 'Cached for a minute');

        AccountBalances::forget();
        $this->get(route('money'))->assertOk();
        $this->assertCount(2, $this->stripe->callsOf('balance'));
    }

    public function test_stripe_unreachable_still_opens_the_page_and_says_so(): void
    {
        $this->fakeStripe();
        app(OperatingAccountSetup::class)->create('127.0.0.1');
        $this->stripe->accountBalanceFailure = 'Stripe could not be reached.';

        $this->signIn(Role::PlatformAdmin);
        $this->get(route('money'))->assertOk()->assertSeeText('Couldn\'t read it from Stripe just now.');
    }

    public function test_with_stripe_off_no_stripe_call_is_made(): void
    {
        $this->signIn(Role::PlatformAdmin);

        $this->get(route('money'))->assertOk()
            ->assertSeeText('Stripe test mode is off on this server, so there is no Stripe balance; sweeps are simulated.')
            ->assertDontSee('data-customer-stripe-balance', false);
    }

    public function test_a_one_off_fee_test_is_described_exactly_and_never_as_ongoing_income(): void
    {
        $this->fakeStripe();
        app(OperatingAccountSetup::class)->create('127.0.0.1');
        $redemption = $this->feeTestOnOneSale();

        $this->signIn(Role::PlatformAdmin);
        $this->get(route('money'))->assertOk()
            ->assertSee('data-earned-origin="some"', false)
            ->assertSeeText('1 sale charged a merchant fee ($0.16 in all), on '.$redemption->created_at->setTimezone('America/New_York')->format('M j, Y'))
            ->assertSeeText('while the merchant fee was switched on from Oct 28, 2026, 12:08 PM to 12:11 PM (fee audit log)')
            ->assertSee('data-charges-now="no"', false)
            ->assertSeeText('Otherwise Local Goods charges nothing: the platform fee and the merchant fee are both off now, so this is not ongoing income.');
    }

    public function test_the_sweep_history_lists_each_sweep_for_staff_and_only_a_count_for_judges(): void
    {
        $this->fakeStripe();
        app(OperatingAccountSetup::class)->create('127.0.0.1');
        $this->feeTestOnOneSale();
        $admin = $this->signIn(Role::PlatformAdmin);
        $sweep = app(OperatingSweeps::class)->sweep($admin, 16, 'Kept from the three-minute fee test', 16);

        $this->get(route('money'))->assertOk()->assertSee('data-sweep-history="table"', false)
            ->assertSee('data-sweep="sent"', false)->assertSee($sweep->stripe_transfer_id)
            ->assertSee('Kept from the three-minute fee test')->assertSee(User::initialsOf($admin->name))
            ->assertSee('data-swept>−$0.16', false);

        AccountBalances::forget();
        $reads = count($this->stripe->callsOf('balance')) + count($this->stripe->callsOf('account_balance'));
        $this->signIn(Role::Judge);
        $this->get(route('money'))->assertOk()->assertSee('data-sweep-history="words"', false)->assertSeeText('1 sweep recorded.')
            ->assertDontSee('Kept from the three-minute fee test')->assertDontSee($sweep->stripe_transfer_id)
            ->assertDontSee('data-operating-stripe-balance', false)->assertDontSee('data-customer-stripe-balance', false)
            ->assertDontSee('$0.16')
            ->assertSeeText('1 sale charged a merchant fee, on')
            ->assertSeeText('this is not ongoing income');
        $this->assertSame($reads, count($this->stripe->callsOf('balance')) + count($this->stripe->callsOf('account_balance')), 'A judge\'s visit never reads a Stripe balance.');
    }

    public function test_the_states_the_page_sweeps_never_see_pass_the_accessibility_audit(): void
    {
        // The site-wide sweeps see the seeded demo, with nothing to sweep and no history; these states only appear after a fee.
        $this->fakeStripe();
        app(OperatingAccountSetup::class)->create('127.0.0.1');
        $this->feeTestOnOneSale();
        $admin = $this->signIn(Role::PlatformAdmin);

        $this->assertSame([], AccessibilityAudit::problems($this->get(route('money'))->assertOk()->assertSee('Review the sweep')->getContent()), 'The sweep form');
        $this->assertSame([], AccessibilityAudit::problems($this->post(route('admin.operating.sweep.review'), ['amount' => '0.16', 'reason' => 'Kept from the fee test'])
            ->assertOk()->assertSee('data-sweep-review', false)->getContent()), 'The review step');

        app(OperatingSweeps::class)->sweep($admin, 16, 'Kept from the fee test', 16);
        $this->assertSame([], AccessibilityAudit::problems($this->get(route('money'))->assertOk()->assertSee('data-sweep-history="table"', false)->getContent()), 'The sweep history');
        $this->assertSame([], AccessibilityAudit::problems($this->get(route('admin.reconciliation'))->assertOk()->assertSee('data-operating-check="yes"', false)->getContent()), 'Reconciliation with a sweep');

        $this->signIn(Role::Judge);
        $this->assertSame([], AccessibilityAudit::problems($this->get(route('money'))->assertOk()->assertSee('data-sweep-history="words"', false)->getContent()), 'As a judge');
    }

    /**
     * Like live on 2026-10-28: the merchant fee on from 12:08 to 12:11 PM DC
     * time, one Stripe-paid sale cleared in that window and paid out net of
     * $0.16, then the fee back to none.
     */
    private function feeTestOnOneSale(): Redemption
    {
        MerchantFeeChange::create(['fee_bps' => 250, 'fixed_cents' => 0, 'previous_bps' => 0, 'previous_fixed_cents' => 0, 'reason' => 'Example: checking the fee works'])
            ->forceFill(['created_at' => Carbon::parse('2026-10-28 12:08', 'America/New_York')->utc()])->saveQuietly();
        $this->stripePurchase(50000);
        $this->onboard();
        $redemption = $this->cleared(633);
        MerchantFeeChange::create(['fee_bps' => 0, 'fixed_cents' => 0, 'previous_bps' => 250, 'previous_fixed_cents' => 0, 'reason' => 'Example: back to none'])
            ->forceFill(['created_at' => Carbon::parse('2026-10-28 12:11', 'America/New_York')->utc()])->saveQuietly();
        $this->assertSame(16, (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE)->sum('amount_cents'));

        return $redemption;
    }
}
