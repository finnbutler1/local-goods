<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\OperatingAccount;
use App\Models\OperatingSweep;
use App\Models\User;
use App\Services\CreditLedger;
use App\Services\Payments\OperatingAccountSetup;
use App\Services\Payments\OperatingSweeps;
use App\Services\Solvency\SolvencyCheck;
use App\Services\Solvency\SolvencyGuard;
use App\Services\Solvency\SolvencyRefused;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Sweeps to Local Goods Operating (ADR 0034): only money Local Goods has
 * earned, only by the platform admin with a reason, against the amount
 * they reviewed, reserved under the solvency guard before Stripe is asked,
 * and only ever to the operating account.
 */
class OperatingSweepTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    /** 2.5% platform fee on a $64.00 purchase. */
    private const EARNED = 160;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_stripe_sweep_sends_exactly_what_was_earned_to_the_operating_account(): void
    {
        $account = $this->stripeWithEarnings();
        $sweeps = app(OperatingSweeps::class);
        $this->assertSame(self::EARNED, $sweeps->sweepable());

        $sweep = $sweeps->sweep($this->admin(), self::EARNED, 'Fees earned this week', self::EARNED);

        $this->assertSame(OperatingSweep::STATUS_SENT, $sweep->status);
        $this->assertNull($sweep->open_key);
        $this->assertNotNull($sweep->swept_at);
        [$transfer] = $this->stripe->callsOf('transfer');
        $this->assertSame($account->stripe_account_id, $transfer['destination']);
        $this->assertSame(self::EARNED, $transfer['amount']);
        $this->assertArrayNotHasKey('source_transaction', $transfer);
        $this->assertSame((string) $sweep->id, $transfer['metadata']['lg_sweep_id']);
        $this->assertArrayNotHasKey('lg_payout_key', $transfer['metadata'], 'Never counted as a merchant payout');
        $this->assertSame(self::EARNED, self::usd($this->stripe->retrieveAccountBalance($account->stripe_account_id)));

        $this->assertSame(0, $sweeps->sweepable());
        $this->assertSame(-self::EARNED, (int) $sweep->ledgerEntries()->sum('amount_cents'));
        $this->assertSolventEverywhere();
    }

    public function test_a_second_sweep_of_the_same_money_is_refused(): void
    {
        $this->stripeWithEarnings();
        app(OperatingSweeps::class)->sweep($this->admin(), self::EARNED, 'Fees earned this week', self::EARNED);

        $this->expectException(InvalidArgumentException::class);
        app(OperatingSweeps::class)->sweep($this->admin(), self::EARNED, 'Fees earned this week', self::EARNED);
    }

    public function test_more_than_was_earned_a_stale_review_and_a_short_reason_are_refused_and_nothing_is_written(): void
    {
        $this->stripeWithEarnings();
        $lines = CreditLedgerEntry::count();

        foreach ([[self::EARNED + 1, 'A good reason', self::EARNED], [self::EARNED, 'A good reason', self::EARNED - 10], [self::EARNED, 'no', self::EARNED], [0, 'A good reason', self::EARNED]] as [$cents, $reason, $reviewed]) {
            try {
                app(OperatingSweeps::class)->sweep($this->admin(), $cents, $reason, $reviewed);
                $this->fail("A sweep of {$cents} reviewed against {$reviewed} was allowed.");
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame($lines, CreditLedgerEntry::count());
        $this->assertSame(0, OperatingSweep::count());
        $this->assertSame([], $this->stripe->callsOf('transfer'));
    }

    public function test_only_a_platform_admin_sweeps(): void
    {
        $this->stripeWithEarnings();

        $this->expectException(LogicException::class);
        app(OperatingSweeps::class)->sweep($this->signIn(Role::BidAdmin), self::EARNED, 'Fees earned this week', self::EARNED);
    }

    public function test_the_guard_itself_refuses_a_sweep_of_more_than_was_earned(): void
    {
        // The service checks the amount first; this skips that, to show the guard catches it on its own.
        $this->stripeWithEarnings();

        try {
            app(SolvencyGuard::class)->transaction('a sweep with the amount check skipped', function () {
                $sweep = OperatingSweep::create(['amount_cents' => self::EARNED + 500, 'channel' => OperatingSweep::CHANNEL_SIMULATED,
                    'status' => OperatingSweep::STATUS_SIMULATED, 'reason' => 'Example reason', 'reviewed_against_cents' => self::EARNED]);
                CreditLedgerEntry::create(['platform' => true, 'amount_cents' => -$sweep->amount_cents, 'type' => CreditLedgerEntry::TYPE_OPERATING_SWEEP,
                    'operating_sweep_id' => $sweep->id, 'description' => 'A bug']);
            });
            $this->fail('A sweep of more than was earned was allowed.');
        } catch (SolvencyRefused $e) {
            $this->assertStringContainsString('short by $5.00', $e->getMessage());
        }

        $this->assertSame(0, OperatingSweep::count(), 'Rolled back');
    }

    public function test_a_definite_refusal_puts_the_money_back_and_a_new_sweep_can_be_made(): void
    {
        $this->stripeWithEarnings();
        $this->stripe->transferFailures = [['Stripe says the platform balance is too low for this transfer yet.', true]];

        $sweep = app(OperatingSweeps::class)->sweep($this->admin(), self::EARNED, 'Fees earned this week', self::EARNED);

        $this->assertSame(OperatingSweep::STATUS_FAILED, $sweep->status);
        $this->assertSame(1, $sweep->attempts);
        $this->assertSame(0, (int) $sweep->ledgerEntries()->sum('amount_cents'), 'Sweep and returned lines net to nothing');
        $this->assertSame(self::EARNED, app(OperatingSweeps::class)->sweepable());
        $this->assertSolventEverywhere();

        $again = app(OperatingSweeps::class)->sweep($this->admin(), self::EARNED, 'Trying again', self::EARNED);
        $this->assertSame(OperatingSweep::STATUS_SENT, $again->status);
    }

    public function test_a_lost_answer_stays_pending_blocks_another_sweep_and_retries_with_the_same_key(): void
    {
        $this->stripeWithEarnings();
        $this->stripe->transferFailures = [['Stripe did not answer in time.', false]];

        $sweep = app(OperatingSweeps::class)->sweep($this->admin(), self::EARNED, 'Fees earned this week', self::EARNED);
        $this->assertSame(OperatingSweep::STATUS_PENDING, $sweep->status);
        $this->assertSame(OperatingSweep::OPEN_KEY, $sweep->open_key);
        $key = $sweep->idempotency_key;

        try {
            app(OperatingSweeps::class)->sweep($this->admin(), 1, 'Another one', 0);
            $this->fail('A second sweep was allowed while one is pending.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Another sweep is still being sent', $e->getMessage());
        }

        $sent = app(OperatingSweeps::class)->send($sweep);
        $this->assertSame(OperatingSweep::STATUS_SENT, $sent->status);
        $this->assertSame([$key, $key], array_values(array_filter($this->stripe->idempotencyKeys, fn ($k) => str_starts_with($k, 'lg-sweep-'))));
        $this->assertSolventEverywhere();
    }

    public function test_money_earned_on_simulated_activity_is_never_swept_with_real_money(): void
    {
        $this->fakeStripe();
        app(OperatingAccountSetup::class)->create('127.0.0.1');
        config(['payments.platform_fee_percent' => '2.5']);
        $ledger = app(CreditLedger::class);
        $ledger->payPurchase($ledger->requestPurchase(Employer::firstOrFail(), 6400, 'MPG-SIMULATED-FEE'));

        $this->assertSame(0, app(OperatingSweeps::class)->sweepable());
    }

    public function test_with_stripe_off_a_sweep_is_simulated_and_nothing_goes_to_stripe(): void
    {
        config(['payments.platform_fee_percent' => '2.5']);
        $ledger = app(CreditLedger::class);
        $ledger->payPurchase($ledger->requestPurchase(Employer::firstOrFail(), 6400, 'MPG-SIMULATED-FEE'));

        $sweep = app(OperatingSweeps::class)->sweep($this->admin(), self::EARNED, 'Fees earned this week', self::EARNED);

        $this->assertSame(OperatingSweep::STATUS_SIMULATED, $sweep->status);
        $this->assertSame(OperatingSweep::CHANNEL_SIMULATED, $sweep->channel);
        $this->assertSolventEverywhere();
    }

    public function test_stripe_mode_needs_the_operating_account_first(): void
    {
        $this->fakeStripe();
        config(['payments.platform_fee_percent' => '2.5']);
        $this->stripePurchase(6400);

        $this->expectExceptionMessage('Local Goods Operating is not set up in Stripe yet');
        app(OperatingSweeps::class)->sweep($this->admin(), self::EARNED, 'Fees earned this week', self::EARNED);
    }

    public function test_a_sweep_can_never_go_to_a_merchant(): void
    {
        $account = $this->stripeWithEarnings();
        Merchant::orderBy('id')->firstOrFail()->forceFill(['stripe_account_id' => $account->stripe_account_id])->save();

        $this->expectException(LogicException::class);
        try {
            app(OperatingSweeps::class)->sweep($this->admin(), self::EARNED, 'Fees earned this week', self::EARNED);
        } finally {
            $this->assertSame([], $this->stripe->callsOf('transfer'));
        }
    }

    public function test_review_writes_nothing_and_confirm_needs_the_box_ticked(): void
    {
        $this->stripeWithEarnings();
        $this->signIn(Role::PlatformAdmin);

        $this->post(route('admin.operating.sweep.review'), ['amount' => '1.60', 'reason' => 'Fees earned this week'])->assertOk()
            ->assertSee('data-sweep-review', false)->assertSee('$1.60');
        $this->assertSame(0, OperatingSweep::count());

        $confirm = ['amount_cents' => self::EARNED, 'reason' => 'Fees earned this week', 'reviewed_against_cents' => self::EARNED];
        $this->post(route('admin.operating.sweep'), $confirm)->assertSessionHasErrors('confirm');
        $this->assertSame(0, OperatingSweep::count());

        $this->post(route('admin.operating.sweep'), $confirm + ['confirm' => '1'])->assertRedirect(route('money').'#sweep')
            ->assertSessionHas('status', fn ($m) => str_starts_with($m, 'Swept $1.60 to Local Goods Operating: Stripe test-mode transfer tr_'));
        $this->assertSame(1, OperatingSweep::count());
    }

    public function test_reviewing_more_than_can_be_swept_is_an_error_on_the_field(): void
    {
        $this->stripeWithEarnings();
        $this->signIn(Role::PlatformAdmin);

        $this->post(route('admin.operating.sweep.review'), ['amount' => '1.61', 'reason' => 'Fees earned this week'])
            ->assertSessionHasErrors(['amount' => 'You can sweep between $0.01 and $1.60.']);
    }

    public function test_only_the_platform_admin_may_post_and_judges_see_no_amounts(): void
    {
        $this->stripeWithEarnings();
        foreach ([Role::BidAdmin, Role::EmployerAdmin, Role::Merchant, Role::Employee] as $role) {
            $this->signIn($role);
            $this->post(route('admin.operating.sweep.review'), ['amount' => '1.60', 'reason' => 'Fees earned this week'])->assertForbidden();
            $this->post(route('admin.operating.sweep'), ['amount_cents' => self::EARNED, 'reason' => 'Fees earned this week', 'reviewed_against_cents' => self::EARNED, 'confirm' => '1'])->assertForbidden();
        }
        $this->assertSame(0, OperatingSweep::count());

        $this->signIn(Role::Judge);
        $this->get(route('money'))->assertOk()->assertSee('data-sweep="words"', false)->assertDontSee('data-sweepable', false);
        $this->signIn(Role::BidAdmin);
        $this->get(route('money'))->assertOk()->assertSee('data-sweepable', false)->assertSee('Read-only: only the platform admin can sweep.');
    }

    public function test_with_nothing_earned_the_page_says_so_in_words(): void
    {
        $this->signIn(Role::PlatformAdmin);

        $this->get(route('money'))->assertOk()->assertSee('data-sweep="nothing"', false)->assertSee('Nothing to sweep.')
            ->assertDontSee('Review the sweep');
    }

    /** Stripe test mode, the operating account set up, and $1.60 earned as a platform fee on a Stripe-paid purchase. */
    private function stripeWithEarnings(): OperatingAccount
    {
        $this->fakeStripe();
        $account = app(OperatingAccountSetup::class)->create('127.0.0.1');
        config(['payments.platform_fee_percent' => '2.5']);
        $purchase = $this->stripePurchase(6400);
        $this->assertSame(self::EARNED, $purchase->platform_fee_cents);
        $this->assertSame(CreditPurchase::STATUS_PAID, $purchase->status);

        return $account;
    }

    private function admin(): User
    {
        return User::where('role', Role::PlatformAdmin)->first() ?? $this->signIn(Role::PlatformAdmin);
    }

    private function assertSolventEverywhere(): void
    {
        $now = app(SolvencyCheck::class)->now();
        $this->assertTrue($now->passes(), (string) $now->problem());
        $history = app(SolvencyCheck::class)->history();
        $this->assertSame([], $history->windows);
        $this->assertSame([], $history->mismatches);
        $this->assertSame($now->toArray(), $history->final->toArray());
    }

    private static function usd(array $balance): int
    {
        return array_sum(array_column($balance['available'], 'amount'));
    }
}
