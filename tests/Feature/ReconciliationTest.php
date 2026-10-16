<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
use App\Services\Payments\StripeOnboarding;
use App\Services\RedemptionService;
use App\Support\StripeEnvironment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * /admin/reconciliation: the ledger next to Stripe, Stripe-funded activity
 * only, with each difference spelled out (ADR 0015).
 */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private CreditPurchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();

        $ledger = app(CreditLedger::class);
        $this->purchase = $ledger->requestPurchase(Employer::firstOrFail(), 50000, 'MPG-STRIPE-1');
        $this->purchase->update(['payment_token' => 'tok-1']);
        $ledger->markPurchasePaidByStripe($this->purchase, 'pi_test_1', 'ch_test_1', 50000, 'usd');

        $merchant = Merchant::where('name', 'Farragut Grind')->firstOrFail();
        $this->stripe->accounts['acct_test_FG'] = ['id' => 'acct_test_FG'];
        app(StripeOnboarding::class)->apply($merchant, $this->stripe->completeOnboarding('acct_test_FG'));
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));
        app(RedemptionService::class)->redeem(Employee::orderBy('id')->firstOrFail(), $merchant, 1250);

        $this->stripe->paymentIntents = [[
            'id' => 'pi_test_1', 'status' => 'succeeded', 'amount' => 50000, 'amount_received' => 50000, 'currency' => 'usd',
            'metadata' => ['lg_purchase_token' => 'tok-1'],
        ]];
        $this->stripe->balance = ['available' => [['amount' => 48700, 'currency' => 'usd']], 'pending' => [['amount' => 0, 'currency' => 'usd']]];

        $this->signIn(Role::PlatformAdmin);
    }

    public function test_matching_activity_says_everything_matches(): void
    {
        $this->get('/admin/reconciliation')->assertOk()
            ->assertSee('Does the ledger agree with Stripe?')
            ->assertSee('data-reconciled="yes"', false)
            ->assertSee('$500.00')
            ->assertSee('$12.50')
            ->assertSee('Not part of this check');
    }

    public function test_a_payment_stripe_has_but_the_ledger_does_not_is_highlighted_in_plain_english(): void
    {
        $this->stripe->paymentIntents[] = ['id' => 'pi_test_extra', 'status' => 'succeeded', 'amount' => 25000, 'amount_received' => 25000, 'currency' => 'usd', 'metadata' => ['lg_purchase_token' => 'unknown'] + StripeEnvironment::tag()];

        $this->get('/admin/reconciliation')->assertOk()
            ->assertSee('data-reconciled="no"', false)
            ->assertSee('Stripe has a $250.00 payment (pi_test_extra) that Local Goods has no ledger record of (for example a purchase paid twice). Check before refunding it in Stripe.')
            ->assertSee('Purchases: the ledger counts $500.00 paid through Stripe; Stripe received $750.00. Difference: $250.00.')
            ->assertDontSee('may need a refund');
    }

    public function test_same_environment_objects_are_counted(): void
    {
        $this->stripe->paymentIntents[0]['metadata'] += StripeEnvironment::tag();
        $this->assertSame(StripeEnvironment::SAME, StripeEnvironment::of($this->stripe->transfers[0]['metadata']), 'Transfers we send are tagged');

        $this->get('/admin/reconciliation')->assertOk()
            ->assertSee('data-reconciled="yes"', false)
            ->assertSee(StripeEnvironment::label())
            ->assertDontSee('From another environment, not counted');
    }

    public function test_other_environment_objects_are_listed_but_never_counted(): void
    {
        $local = ['lg_env' => 'local', 'lg_instance' => 'abcd1234'];
        $this->stripe->paymentIntents[] = ['id' => 'pi_test_local', 'status' => 'succeeded', 'amount' => 50000, 'amount_received' => 50000, 'currency' => 'usd', 'metadata' => ['lg_purchase_token' => 'tok-1'] + $local];
        $this->stripe->transfers[] = ['id' => 'tr_test_local', 'amount' => 800, 'destination' => 'acct_test_local', 'metadata' => ['lg_payout_key' => 'lg-transfer-local'] + $local];

        $this->get('/admin/reconciliation')->assertOk()
            ->assertSee('data-reconciled="yes"', false)
            ->assertSee('From another environment, not counted')
            ->assertSee('1 payment ($500.00)')
            ->assertSee('1 transfer ($8.00)')
            ->assertSee('A $500.00 payment (pi_test_local) made by another environment (local · abcd1234).')
            ->assertSee('A $8.00 transfer (tr_test_local) to acct_test_local made by another environment (local · abcd1234).')
            ->assertDontSee('refund');
    }

    /** What production saw: a payment and a transfer from local testing, made before tagging. */
    public function test_untagged_objects_unknown_here_are_information_not_mismatches(): void
    {
        $this->stripe->paymentIntents[] = ['id' => 'pi_test_legacy', 'status' => 'succeeded', 'amount' => 50000, 'amount_received' => 50000, 'currency' => 'usd', 'metadata' => ['lg_purchase_token' => 'someone-elses-token']];
        $this->stripe->transfers[] = ['id' => 'tr_test_legacy', 'amount' => 800, 'destination' => 'acct_test_legacy', 'metadata' => ['lg_payout_key' => 'lg-transfer-someone-else']];

        $this->get('/admin/reconciliation')->assertOk()
            ->assertSee('data-reconciled="yes"', false)
            ->assertSee('From another environment, not counted')
            ->assertSee('A $500.00 payment (pi_test_legacy) with no environment tag (made before tagging) and no record here. Most likely another environment&#039;s; if it was made from this one, check before refunding.', false)
            ->assertSee('A $8.00 transfer (tr_test_legacy) to acct_test_legacy with no environment tag')
            ->assertDontSee('may need a refund');
    }

    public function test_untagged_objects_this_database_knows_are_still_counted(): void
    {
        // The setUp payment is untagged (made "before tagging") but its id is in the ledger.
        $this->assertSame(StripeEnvironment::UNTAGGED, StripeEnvironment::of($this->stripe->paymentIntents[0]['metadata']));
        unset($this->stripe->transfers[0]['metadata']['lg_env'], $this->stripe->transfers[0]['metadata']['lg_instance']);

        $this->get('/admin/reconciliation')->assertOk()
            ->assertSee('data-reconciled="yes"', false)
            ->assertDontSee('From another environment, not counted');
    }

    public function test_a_missed_webhook_is_explained(): void
    {
        $pending = app(CreditLedger::class)->requestPurchase(Employer::firstOrFail(), 10000, 'MPG-STRIPE-2');
        $pending->update(['payment_token' => 'tok-2']);
        $this->stripe->paymentIntents[] = ['id' => 'pi_test_2', 'status' => 'succeeded', 'amount' => 10000, 'amount_received' => 10000, 'currency' => 'usd', 'metadata' => ['lg_purchase_token' => 'tok-2']];

        $this->get('/admin/reconciliation')->assertSee('but Local Goods still shows it pending: the webhook has not arrived');
    }

    public function test_a_transfer_missing_from_stripe_is_highlighted(): void
    {
        $this->stripe->transfers = [];

        $this->get('/admin/reconciliation')->assertOk()
            ->assertSee('data-reconciled="no"', false)
            ->assertSee('but Stripe has no such transfer since the last reset');
    }

    public function test_simulated_payouts_are_never_part_of_the_check(): void
    {
        $this->assertGreaterThan(0, MerchantPayout::where('status', MerchantPayout::STATUS_SIMULATED)->count());

        $this->get('/admin/reconciliation')->assertSee('data-reconciled="yes"', false);
    }

    public function test_without_keys_it_explains_and_calls_nothing(): void
    {
        config(['services.stripe.key' => null, 'services.stripe.secret' => null, 'services.stripe.webhook_secret' => null]);
        $this->stripe->calls = [];

        $this->get('/admin/reconciliation')->assertOk()->assertSee('Stripe test mode is off')->assertSee('No Stripe keys are set');
        $this->assertSame([], $this->stripe->calls);
    }

    public function test_live_keys_are_refused_without_showing_them(): void
    {
        config(['services.stripe.key' => 'pk_live_SHOULDNOTSHOW', 'services.stripe.secret' => 'sk_live_SHOULDNOTSHOW']);

        $this->get('/admin/reconciliation')->assertOk()
            ->assertSee('not test-mode keys')
            ->assertDontSee('SHOULDNOTSHOW');
    }

    public function test_retry_sends_what_is_owed(): void
    {
        $this->stripe->transferFailures = [['Refused.']];
        app(RedemptionService::class)->redeem(Employee::orderBy('id')->firstOrFail(), Merchant::where('name', 'Farragut Grind')->firstOrFail(), 500);
        $this->assertSame(MerchantPayout::STATUS_FAILED, MerchantPayout::latest('id')->value('status'));

        $this->post('/admin/reconciliation/retry')->assertRedirect('/admin/reconciliation');

        $this->assertSame(MerchantPayout::STATUS_SENT, MerchantPayout::latest('id')->value('status'));
    }

    public function test_only_the_platform_admin_can_open_it(): void
    {
        foreach ([Role::BidAdmin, Role::EmployerAdmin, Role::Merchant, Role::Judge] as $role) {
            $this->signIn($role);
            $this->get('/admin/reconciliation')->assertForbidden();
        }
    }
}
