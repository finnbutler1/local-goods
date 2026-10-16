<?php

namespace Tests\Feature;

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
use App\Services\Payments\MerchantPayouts;
use App\Services\Payments\StripeOnboarding;
use App\Services\RedemptionReviewService;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Cleared redemptions become Stripe transfers (ADR 0015), but only the
 * Stripe-funded ones: cleared in Stripe test mode and covered by a
 * Stripe-paid purchase. Seeded and simulated money owed never reaches
 * Stripe. Held, rejected and pending redemptions never transfer.
 */
class StripePayoutTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private Employee $employee;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->employee = Employee::orderBy('id')->firstOrFail();
        $this->merchant = Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail();
    }

    /** A purchase paid by card in Stripe test mode (as the webhook records it). */
    private function stripePurchase(int $cents = 50000, string $charge = 'ch_test_A'): CreditPurchase
    {
        $ledger = app(CreditLedger::class);
        $purchase = $ledger->requestPurchase(Employer::firstOrFail(), $cents, 'MPG-STRIPE-'.$charge);
        $ledger->markPurchasePaidByStripe($purchase, 'pi_'.$charge, $charge, $cents, 'usd');

        return $purchase->fresh();
    }

    private function onboard(?Merchant $merchant = null): void
    {
        $merchant ??= $this->merchant;
        $this->stripe->accounts['acct_test_FG'] = ['id' => 'acct_test_FG'];
        app(StripeOnboarding::class)->apply($merchant, $this->stripe->completeOnboarding('acct_test_FG'));
    }

    private function redeem(int $cents, string $decision): Redemption
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict($decision === 'hold' ? 90 : 10, $decision));

        return app(RedemptionService::class)->redeem($this->employee->fresh(), $this->merchant, $cents);
    }

    public function test_a_cleared_stripe_funded_redemption_is_transferred_once_with_its_transfer_group_and_charge(): void
    {
        $purchase = $this->stripePurchase();
        $this->onboard();
        $owedBefore = $this->merchant->payableCents();

        $redemption = $this->redeem(1250, 'clear');

        [$transfer] = $this->stripe->callsOf('transfer');
        $this->assertSame(1250, $transfer['amount']);
        $this->assertSame('usd', $transfer['currency']);
        $this->assertSame('acct_test_FG', $transfer['destination']);
        $this->assertSame($purchase->reference, $transfer['transfer_group']);
        $this->assertSame('ch_test_A', $transfer['source_transaction']);

        $payout = MerchantPayout::where('redemption_id', $redemption->id)->firstOrFail();
        $this->assertSame(MerchantPayout::STATUS_SENT, $payout->status);
        $this->assertStringStartsWith('tr_test_', $payout->stripe_transfer_id);
        $this->assertSame(1, CreditLedgerEntry::where('merchant_payout_id', $payout->id)->where('amount_cents', -1250)->count());
        $this->assertSame($owedBefore, $this->merchant->payableCents(), 'Earned then paid: owed is back where it was');
        $this->assertSame(0, $this->merchant->owedViaStripeCents());

        // Sending again changes nothing.
        app(MerchantPayouts::class)->send($this->merchant);
        $this->assertCount(1, $this->stripe->callsOf('transfer'));
    }

    public function test_held_and_rejected_redemptions_never_transfer_and_an_approved_one_does_once(): void
    {
        $this->stripePurchase();
        $this->onboard();

        $held = $this->redeem(3000, 'hold');
        $rejected = $this->redeem(2000, 'hold');
        app(MerchantPayouts::class)->send($this->merchant);

        $this->assertSame([], $this->stripe->callsOf('transfer'), 'Held redemptions never transfer');
        $this->assertSame(0, MerchantPayout::whereIn('redemption_id', [$held->id, $rejected->id])->count());

        $reviewer = User::factory()->role(Role::BidAdmin)->create();
        app(RedemptionReviewService::class)->reject($rejected, $reviewer);
        $this->assertSame([], $this->stripe->callsOf('transfer'), 'Rejected redemptions never transfer');

        app(RedemptionReviewService::class)->approve($held, $reviewer);
        $this->assertCount(1, $this->stripe->callsOf('transfer'));
        $this->assertSame(3000, $this->stripe->callsOf('transfer')[0]['amount']);
        $this->assertSame(MerchantPayout::STATUS_SENT, MerchantPayout::where('redemption_id', $held->id)->value('status'));
    }

    public function test_it_stays_owed_until_onboarding_is_finished_then_transfers(): void
    {
        $this->stripePurchase();
        $redemption = $this->redeem(1250, 'clear');

        $this->assertSame([], $this->stripe->callsOf('transfer'));
        $this->assertSame(MerchantPayout::STATUS_OWED, MerchantPayout::where('redemption_id', $redemption->id)->value('status'));
        $this->assertSame(1250, $this->merchant->owedViaStripeCents());

        // Started but not finished: still nothing.
        $this->merchant->update(['stripe_account_id' => 'acct_test_FG', 'stripe_transfers_active' => false]);
        app(MerchantPayouts::class)->send($this->merchant);
        $this->assertSame([], $this->stripe->callsOf('transfer'));

        $this->postWebhook($this->stripeEvent('account.updated', $this->stripe->completeOnboarding('acct_test_FG')))->assertOk();

        $this->assertCount(1, $this->stripe->callsOf('transfer'));
        $this->assertSame(MerchantPayout::STATUS_SENT, MerchantPayout::where('redemption_id', $redemption->id)->value('status'));
        $this->assertSame(0, $this->merchant->owedViaStripeCents());
    }

    /** Finn's rule: seeded/simulated owed money stays simulated and is never sent through Stripe. */
    public function test_seeded_simulated_owed_amounts_never_trigger_a_stripe_transfer(): void
    {
        // Whatever the seed left owed since its last Friday payout, plus a
        // redemption cleared before Stripe was switched on (simulated mode).
        config(['services.stripe.secret' => null]);
        $this->redeem(2000, 'clear');
        config(['services.stripe.secret' => 'sk_test_fake']);
        $seededOwed = $this->merchant->payableCents();
        $this->assertGreaterThanOrEqual(2000, $seededOwed);

        // Onboard, the webhook, the return page and Retry: every path that sends.
        $this->onboard();
        $this->postWebhook($this->stripeEvent('account.updated', $this->stripe->completeOnboarding('acct_test_FG')))->assertOk();
        app(MerchantPayouts::class)->send($this->merchant);
        foreach (Merchant::all() as $merchant) {
            app(MerchantPayouts::class)->send($merchant);
        }

        $this->assertSame([], $this->stripe->callsOf('transfer'));
        $this->assertSame($seededOwed, $this->merchant->owedSimulatedCents());
        $this->assertSame(0, MerchantPayout::stripe()->count());

        // A cleared redemption with only simulated purchases behind it stays simulated too.
        $this->redeem(1500, 'clear');
        $this->assertSame([], $this->stripe->callsOf('transfer'));
        $this->assertSame($seededOwed + 1500, $this->merchant->owedSimulatedCents());

        // Once there is a Stripe-paid purchase, only the new redemption is sent; the seeded amount isn't.
        $this->stripePurchase();
        $this->redeem(700, 'clear');
        $this->assertSame([700], array_column($this->stripe->callsOf('transfer'), 'amount'));
        $this->assertSame($seededOwed + 1500, $this->merchant->owedSimulatedCents());

        $this->get('/merchant')->assertRedirect(); // signed out
        $this->signIn(Role::Merchant);
        $this->get('/merchant')->assertOk()
            ->assertSee('Owed via Stripe test mode')
            ->assertSee('Owed, simulated')
            ->assertSee('data-owed="simulated">'.\App\Support\Money::format($seededOwed + 1500), false)
            ->assertSee('data-owed="stripe">$0.00', false);
    }

    public function test_a_redemption_beyond_the_stripe_paid_money_stays_simulated(): void
    {
        $this->stripePurchase(1000);
        $this->onboard();

        $this->redeem(1500, 'clear');
        $this->assertSame([], $this->stripe->callsOf('transfer'));

        $this->redeem(600, 'clear');
        $this->redeem(600, 'clear');
        $this->assertSame([600], array_column($this->stripe->callsOf('transfer'), 'amount'), 'Only $10.00 of Stripe money to spend: the second $6.00 stays simulated');
        $this->assertLessThanOrEqual(1000, (int) MerchantPayout::stripe()->sum('amount_cents'));
    }

    public function test_purchases_are_matched_oldest_first(): void
    {
        $this->stripePurchase(1000, 'ch_old');
        $this->stripePurchase(5000, 'ch_new');
        $this->onboard();

        $this->redeem(800, 'clear');
        $this->redeem(800, 'clear');

        $this->assertSame(['ch_old', 'ch_new'], array_column($this->stripe->callsOf('transfer'), 'source_transaction'));
    }

    public function test_a_refused_transfer_stays_owed_and_is_retried_with_a_new_key(): void
    {
        $this->stripePurchase();
        $this->onboard();
        $this->stripe->transferFailures = [['Stripe says the platform balance is too low for this transfer yet.']];

        $redemption = $this->redeem(1250, 'clear');

        $payout = MerchantPayout::where('redemption_id', $redemption->id)->firstOrFail();
        $this->assertSame(MerchantPayout::STATUS_FAILED, $payout->status);
        $this->assertSame('Stripe says the platform balance is too low for this transfer yet.', $payout->failure_reason);
        $this->assertSame(1250, $this->merchant->owedViaStripeCents());
        $this->assertSame(Redemption::STATUS_APPROVED, $redemption->fresh()->status, 'A Stripe failure never undoes the redemption');
        $this->assertSame(0, CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_PAYOUT)->where('merchant_payout_id', $payout->id)->count(), 'No payout entry');
        $firstKey = $payout->idempotency_key;

        app(MerchantPayouts::class)->send($this->merchant);

        $this->assertSame(MerchantPayout::STATUS_SENT, $payout->fresh()->status);
        $this->assertNotSame($firstKey, $payout->fresh()->idempotency_key);
        $this->assertSame(0, $this->merchant->owedViaStripeCents());
    }

    public function test_a_timeout_keeps_the_same_key_so_stripe_cannot_pay_twice(): void
    {
        $this->stripePurchase();
        $this->onboard();
        $this->stripe->transferFailures = [['Could not reach Stripe.', false]];

        $redemption = $this->redeem(1250, 'clear');
        $payout = MerchantPayout::where('redemption_id', $redemption->id)->firstOrFail();
        $this->assertSame(MerchantPayout::STATUS_PENDING, $payout->status);

        app(MerchantPayouts::class)->send($this->merchant);
        $this->assertSame($payout->idempotency_key, $payout->fresh()->idempotency_key);
        $this->assertSame(MerchantPayout::STATUS_SENT, $payout->fresh()->status);
    }

    public function test_a_transfer_created_webhook_records_a_transfer_whose_answer_was_lost(): void
    {
        $this->stripePurchase();
        $this->onboard();
        $this->stripe->transferFailures = [['Could not reach Stripe.', false]];
        $payout = MerchantPayout::where('redemption_id', $this->redeem(1250, 'clear')->id)->firstOrFail();

        $event = $this->stripeEvent('transfer.created', ['id' => 'tr_test_lost', 'object' => 'transfer', 'amount' => 1250, 'metadata' => ['lg_payout_key' => $payout->idempotency_key]]);
        $this->postWebhook($event)->assertOk()->assertJson(['outcome' => 'transfer recorded from webhook: tr_test_lost']);
        $this->postWebhook($event)->assertOk()->assertJson(['outcome' => 'duplicate: already handled, nothing changed']);

        $this->assertSame([MerchantPayout::STATUS_SENT, 'tr_test_lost'], [$payout->fresh()->status, $payout->fresh()->stripe_transfer_id]);
        $this->assertSame(1, CreditLedgerEntry::where('merchant_payout_id', $payout->id)->count());
    }

    public function test_a_reversed_transfer_is_owed_again_and_not_resent_automatically(): void
    {
        $this->stripePurchase();
        $this->onboard();
        $payout = MerchantPayout::where('redemption_id', $this->redeem(1250, 'clear')->id)->firstOrFail();

        $event = $this->stripeEvent('transfer.reversed', ['id' => $payout->stripe_transfer_id, 'object' => 'transfer', 'amount' => 1250, 'reversed' => true]);
        $this->postWebhook($event)->assertOk();
        $this->postWebhook($this->stripeEvent('transfer.reversed', $event['data']['object']))->assertOk()->assertJsonPath('outcome', fn ($o) => str_starts_with($o, 'reversal already recorded'));

        $this->assertSame(MerchantPayout::STATUS_REVERSED, $payout->fresh()->status);
        $this->assertSame(1250, $this->merchant->owedViaStripeCents());
        app(MerchantPayouts::class)->send($this->merchant);
        $this->assertCount(1, $this->stripe->callsOf('transfer'));
    }

    public function test_the_ledger_invariant_holds_with_stripe_payouts(): void
    {
        $this->stripePurchase();
        $this->onboard();
        $this->redeem(1250, 'clear');
        $this->redeem(900, 'hold');
        $this->stripe->transferFailures = [['Refused.']];
        $this->redeem(400, 'clear');

        $purchased = (int) CreditPurchase::whereIn('status', CreditPurchase::PAID_STATUSES)->sum('amount_cents');
        $paidOut = (int) MerchantPayout::paid()->sum('amount_cents');
        $held = (int) Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->sum('amount_cents');

        $this->assertSame($purchased - $paidOut - $held, (int) CreditLedgerEntry::sum('amount_cents'));
    }

    public function test_no_employee_name_or_initials_are_sent_to_stripe(): void
    {
        $this->stripePurchase();
        $this->onboard();
        $this->redeem(1250, 'clear');

        $sent = json_encode($this->stripe->calls);
        foreach (Employee::all() as $employee) {
            $this->assertStringNotContainsString($employee->display_name, $sent);
            $this->assertStringNotContainsString($employee->publicLabel(), $sent);
        }
    }

    public function test_without_stripe_keys_clearing_never_calls_stripe(): void
    {
        $this->stripePurchase();
        $this->onboard();
        config(['services.stripe.secret' => null]);

        $this->redeem(1250, 'clear');

        $this->assertSame([], $this->stripe->callsOf('transfer'));
        $this->assertSame(0, MerchantPayout::stripe()->count());
    }

    public function test_an_employee_redemption_over_http_still_works_when_stripe_fails(): void
    {
        $this->stripePurchase();
        $this->onboard();
        $this->stripe->transferFailures = [['Refused.']];
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));
        $this->signIn(Role::Employee);

        $this->post('/employee/redeem', ['merchant_id' => $this->merchant->id, 'amount' => '5'])->assertRedirect();

        $this->assertSame(Redemption::STATUS_APPROVED, Redemption::latest('id')->value('status'));
        $this->assertSame(MerchantPayout::STATUS_FAILED, MerchantPayout::latest('id')->value('status'));
    }
}
