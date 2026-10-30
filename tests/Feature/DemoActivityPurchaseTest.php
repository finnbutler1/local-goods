<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Services\CreditLedger;
use App\Services\DemoActivity\StripeTestPayments;
use App\Services\Payments\StripeReconciliation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * The demo activity generator pays for credits with Stripe's test card
 * (ADR 0032), because embedded Checkout needs a person at a browser. The
 * PaymentIntent carries the same metadata as Checkout's, so the same
 * signed webhook marks the purchase paid and reconciliation matches.
 */
class DemoActivityPurchaseTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private CreditPurchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->purchase = app(CreditLedger::class)->requestPurchase(Employer::firstOrFail(), 150000, 'MPG-20261102-1');
    }

    public function test_the_payment_is_labelled_and_the_purchase_waits_for_the_signed_webhook(): void
    {
        $entries = CreditLedgerEntry::count();

        $pi = app(StripeTestPayments::class)->pay($this->purchase);

        [$params] = $this->stripe->callsOf('test_payment');
        $this->assertSame('pm_card_visa', $params['payment_method']);
        $this->assertTrue($params['confirm']);
        $this->assertSame(150000, $params['amount']);
        $this->assertSame('MPG-20261102-1', $params['transfer_group']);
        $this->assertStringContainsString('demo activity', $params['description']);
        $this->assertSame('1', $params['metadata']['lg_demo_activity']);
        $this->assertSame($this->purchase->fresh()->payment_token, $params['metadata']['lg_purchase_token']);
        $this->assertArrayHasKey('lg_env', $params['metadata']);
        $this->assertArrayHasKey('lg_instance', $params['metadata']);
        $this->assertSame('lg-test-payment-'.$this->purchase->fresh()->payment_token, end($this->stripe->idempotencyKeys));

        $this->assertTrue($this->purchase->fresh()->isPending(), 'Nothing is paid until the webhook');
        $this->assertSame($entries, CreditLedgerEntry::count());

        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $pi))->assertOk()->assertJson(['outcome' => 'paid: MPG-20261102-1']);

        $this->assertSame(CreditPurchase::STATUS_PAID, $this->purchase->fresh()->status);
        $this->assertSame($pi['latest_charge'], $this->purchase->fresh()->stripe_charge_id);
        $this->assertSame([], app(StripeReconciliation::class)->report()['mismatches']);
    }

    public function test_a_paid_purchase_is_never_paid_again(): void
    {
        $payments = app(StripeTestPayments::class);
        $first = $payments->pay($this->purchase);

        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $first))->assertOk();

        $this->expectException(LogicException::class);
        $payments->pay($this->purchase->fresh());
    }

    public function test_it_refuses_without_a_test_secret_key(): void
    {
        foreach (['sk_live_not_a_real_key', 'rk_live_not_a_real_key', null] as $secret) {
            config(['services.stripe.secret' => $secret]);

            try {
                app(StripeTestPayments::class)->pay($this->purchase);
                $this->fail('Paid with '.var_export($secret, true));
            } catch (LogicException) {
            }
        }

        $this->assertSame([], $this->stripe->callsOf('test_payment'));
    }

    public function test_it_accepts_only_test_secrets(): void
    {
        $this->assertTrue(StripeTestPayments::isTestSecret('sk_test_fake'));
        $this->assertTrue(StripeTestPayments::isTestSecret('rk_test_fake'));
        $this->assertFalse(StripeTestPayments::isTestSecret('sk_live_fake'));
        $this->assertFalse(StripeTestPayments::isTestSecret(''));
    }
}
