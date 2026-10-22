<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Middleware\TrustCloudFront;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Models\StripeEvent;
use App\Services\CreditLedger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * POST /stripe/webhook (ADR 0015): a valid Stripe signature is the only way
 * in, every event is handled once, and a purchase reaches the ledger only
 * when a verified payment_intent.succeeded says so.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private CreditPurchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->signIn(Role::EmployerAdmin);
        $this->post('/employer/purchases', ['purchase_amount' => '250']);
        $this->purchase = CreditPurchase::latest('id')->firstOrFail();
        // What Stripe.js asks for when the card form opens: makes the Checkout Session.
        $this->postJson("/employer/purchases/{$this->purchase->id}/checkout")->assertOk();
        $this->purchase->refresh();
        auth()->logout();
    }

    private function succeeded(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'pi_test_1', 'object' => 'payment_intent', 'status' => 'succeeded',
            'amount' => 25000, 'amount_received' => 25000, 'currency' => 'usd',
            'latest_charge' => 'ch_test_1', 'transfer_group' => $this->purchase->reference,
            'metadata' => ['lg_purchase_token' => $this->purchase->payment_token, 'lg_purchase_reference' => $this->purchase->reference],
        ];
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return ['entries' => CreditLedgerEntry::count(), 'sum' => (int) CreditLedgerEntry::sum('amount_cents'), 'events' => StripeEvent::count()];
    }

    public function test_a_verified_payment_marks_the_purchase_paid_and_writes_one_entry(): void
    {
        $balance = Employer::firstOrFail()->balanceCents();

        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->succeeded()))
            ->assertOk()->assertJson(['outcome' => 'paid: '.$this->purchase->reference]);

        $purchase = $this->purchase->fresh();
        $this->assertSame([CreditPurchase::STATUS_PAID, 'pi_test_1', 'ch_test_1'], [$purchase->status, $purchase->stripe_payment_intent_id, $purchase->stripe_charge_id]);
        $this->assertSame($balance + 25000, Employer::firstOrFail()->balanceCents());
        $this->assertSame(1, CreditLedgerEntry::where('credit_purchase_id', $purchase->id)->count());
        $this->assertStringContainsString('Stripe test mode (pi_test_1)', CreditLedgerEntry::where('credit_purchase_id', $purchase->id)->value('description'));
    }

    public function test_a_replayed_event_changes_nothing(): void
    {
        $event = $this->stripeEvent('payment_intent.succeeded', $this->succeeded(), id: 'evt_replay');
        $this->postWebhook($event)->assertOk();
        $after = $this->counts();

        $this->postWebhook($event)->assertOk()->assertJson(['outcome' => 'duplicate: already handled, nothing changed']);
        $this->assertSame($after, $this->counts());
    }

    public function test_a_second_event_about_the_same_payment_writes_nothing(): void
    {
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->succeeded()))->assertOk();
        $entries = CreditLedgerEntry::count();

        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->succeeded()))->assertOk()->assertJson(['outcome' => 'already paid: '.$this->purchase->reference]);
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->succeeded(['id' => 'pi_test_2'])))->assertOk()
            ->assertJson(['outcome' => 'already paid by another payment: pi_test_2 not credited (see reconciliation)']);

        $this->assertSame($entries, CreditLedgerEntry::count());
    }

    public function test_the_signature_is_required(): void
    {
        $before = $this->counts();
        $event = $this->stripeEvent('payment_intent.succeeded', $this->succeeded());
        $payload = json_encode($event);

        $this->postWebhook($event, header: '')->assertStatus(400);
        $this->postWebhook($event, header: $this->signedHeader($payload, 'whsec_wrong'))->assertStatus(400);
        $this->postWebhook($event, header: $this->signedHeader($payload, at: time() - 3600))->assertStatus(400);
        // Signed for a different body.
        $this->postWebhook($event, header: $this->signedHeader(json_encode($this->stripeEvent('payment_intent.succeeded', $this->succeeded(['amount_received' => 1])))))->assertStatus(400);

        $this->assertSame($before, $this->counts());
        $this->assertTrue($this->purchase->fresh()->isPending());
    }

    public function test_live_mode_events_are_refused(): void
    {
        $event = ['livemode' => true] + $this->stripeEvent('payment_intent.succeeded', $this->succeeded());

        $this->postWebhook($event)->assertStatus(400);
        $this->assertTrue($this->purchase->fresh()->isPending());
    }

    public function test_an_amount_that_does_not_match_is_refused(): void
    {
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->succeeded(['amount' => 100, 'amount_received' => 100])))
            ->assertOk()->assertJsonPath('outcome', fn ($o) => str_starts_with($o, 'refused:'));

        $this->assertTrue($this->purchase->fresh()->isPending());
    }

    public function test_a_failed_payment_keeps_the_purchase_pending_with_the_reason(): void
    {
        $entries = CreditLedgerEntry::count();

        $this->postWebhook($this->stripeEvent('payment_intent.payment_failed', $this->succeeded([
            'status' => 'requires_payment_method', 'last_payment_error' => ['message' => 'Your card was declined.'],
        ])))->assertOk();

        $this->assertTrue($this->purchase->fresh()->isPending());
        $this->assertSame('Your card was declined.', $this->purchase->fresh()->payment_error);
        $this->assertSame($entries, CreditLedgerEntry::count());
    }

    public function test_an_expired_checkout_lets_the_employer_start_a_new_one(): void
    {
        $oldToken = $this->purchase->payment_token;

        $this->postWebhook($this->stripeEvent('checkout.session.expired', ['id' => $this->purchase->stripe_checkout_session_id, 'object' => 'checkout.session']))->assertOk();

        $this->assertNotSame($oldToken, $this->purchase->fresh()->payment_token);
        $this->assertNull($this->purchase->fresh()->stripe_checkout_session_id);
        // A late success for the old, expired session can't credit it now.
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->succeeded(['metadata' => ['lg_purchase_token' => $oldToken]])))
            ->assertOk()->assertJson(['outcome' => 'ignored: not a Local Goods purchase']);
        $this->assertTrue($this->purchase->fresh()->isPending());
    }

    public function test_unknown_events_and_other_payments_are_ignored(): void
    {
        $before = CreditLedgerEntry::count();

        $this->postWebhook($this->stripeEvent('customer.created', ['id' => 'cus_1']))->assertOk()->assertJson(['outcome' => 'ignored: not an event Local Goods uses']);
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->succeeded(['metadata' => []])))->assertOk()->assertJson(['outcome' => 'ignored: not a Local Goods purchase']);

        $this->assertSame($before, CreditLedgerEntry::count());
    }

    public function test_in_simulated_mode_the_webhook_refuses_everything(): void
    {
        config(['services.stripe.secret' => null]);

        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->succeeded()))->assertStatus(400);
        $this->assertTrue($this->purchase->fresh()->isPending());
    }

    public function test_a_purchase_paid_by_simulation_is_never_paid_again_by_stripe(): void
    {
        app(CreditLedger::class)->payPurchase($this->purchase);
        $entries = CreditLedgerEntry::count();

        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->succeeded()))->assertOk();

        $this->assertSame(CreditPurchase::STATUS_SIMULATED, $this->purchase->fresh()->status);
        $this->assertSame($entries, CreditLedgerEntry::count());
    }

    /**
     * In production the webhook arrives the way CloudFront forwards it
     * (ADR 0009): plain http to the Beanstalk host, from an edge address,
     * with Stripe-Signature passed through. No CSRF token, no cookie.
     */
    public function test_it_works_behind_cloudfront_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://d111example.cloudfront.net', 'services.cloudfront.origin_secret' => 'origin-secret-not-real']);

        $this->postWebhook(
            $this->stripeEvent('payment_intent.succeeded', $this->succeeded()),
            server: ['REMOTE_ADDR' => '130.176.1.10', 'HTTP_X_FORWARDED_FOR' => '3.18.12.63', 'HTTP_'.strtoupper(str_replace('-', '_', TrustCloudFront::SECRET_HEADER)) => 'origin-secret-not-real'],
            url: 'http://local-goods.example.elasticbeanstalk.com/stripe/webhook',
        )->assertOk()->assertJson(['outcome' => 'paid: '.$this->purchase->reference]);

        $this->assertSame(CreditPurchase::STATUS_PAID, $this->purchase->fresh()->status);
    }
}
