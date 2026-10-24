<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Models\MerchantPayout;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\Payments\StripeReconciliation;
use App\Support\StripeEnvironment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * /admin/reconciliation after corrections (ADR 0028): refund reversals,
 * merchant adjustments and card refunds to employers are matched against
 * the ledger, and everything still says "matches" once Stripe's webhooks
 * have arrived. Until they have, the difference is named.
 */
class CorrectionReconciliationTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
    }

    private function report(): array
    {
        return app(StripeReconciliation::class)->report();
    }

    /** Stripe "has" the purchases the ledger counts as Stripe-paid. */
    private function stripeHasPayments(): void
    {
        foreach (CreditPurchase::where('status', 'paid')->get() as $p) {
            $this->stripe->paymentIntents[] = ['id' => $p->stripe_payment_intent_id, 'status' => 'succeeded', 'amount' => $p->totalCents(), 'amount_received' => $p->totalCents(),
                'currency' => 'usd', 'metadata' => ['lg_purchase_token' => $p->payment_token ?? 'tok-'.$p->id] + StripeEnvironment::tag()];
        }
    }

    private function refundedRedemption(int $refund): MerchantPayout
    {
        $this->stripePurchase();
        $this->onboard();
        $payout = MerchantPayout::where('redemption_id', $this->cleared(1250)->id)->sole();
        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($payout->redemption), $this->admin(), 'Refund.', amountCents: $refund);

        return $payout;
    }

    public function test_a_partial_refund_reversal_matches_once_the_webhook_has_arrived(): void
    {
        $payout = $this->refundedRedemption(450);
        $this->stripeHasPayments();

        $before = $this->report();
        $this->assertContains('Transfers: the ledger counts $12.50 sent to merchants through Stripe; Stripe shows $8.00. Difference: -$4.50.', $before['mismatches']);
        $this->assertTrue(collect($before['mismatches'])->contains(fn ($m) => str_contains($m, 'the webhook has not arrived')));

        $this->postWebhook($this->reversedEvent($payout))->assertOk();

        $after = $this->report();
        $this->assertSame([], $after['mismatches']);
        $this->assertSame([800, 800, 450], [$after['ledger']['transfer_cents'], $after['stripe']['transfer_cents'], $after['ledger']['refund_reversal_cents']]);

        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/reconciliation')->assertOk()->assertSee('data-reconciled="yes"', false)
            ->assertSee('after $4.50 pulled back for refunds');
    }

    public function test_a_full_refund_reversal_is_not_reported_as_reversed_but_counted_paid(): void
    {
        $payout = $this->refundedRedemption(1250);
        $this->stripeHasPayments();
        $this->postWebhook($this->reversedEvent($payout))->assertOk();

        $this->assertSame([], $this->report()['mismatches']);
        $this->assertSame(MerchantPayout::STATUS_SENT, $payout->fresh()->status);
    }

    public function test_a_card_refund_to_the_employer_matches_once_the_webhook_has_arrived(): void
    {
        $this->stripePurchase(30000, 'ch_test_A');
        $this->stripeHasPayments();
        $employer = Employer::firstOrFail();
        $request = Correction::create(['type' => Correction::TYPE_EMPLOYER_REFUND, 'status' => Correction::STATUS_REQUESTED, 'open_key' => 'employer:'.$employer->id,
            'requested_by_role' => Role::EmployerAdmin->value, 'reason_category' => 'other', 'employer_id' => $employer->id]);
        app(CorrectionDecisions::class)->approveEmployerRefund($request, $this->admin(), 'Refund.', 12000);

        $before = $this->report();
        $this->assertContains('Refunds to employers: the ledger counts $0.00 refunded to cards through Stripe; Stripe shows $120.00. Difference: $120.00.', $before['mismatches']);
        $this->assertTrue(collect($before['mismatches'])->contains(fn ($m) => str_starts_with($m, 'Stripe refunded $120.00') && str_contains($m, 'webhook has not arrived')));

        $this->postWebhook($this->stripeEvent('refund.created', $this->stripe->refunds[0]))->assertOk();

        $after = $this->report();
        $this->assertSame([], $after['mismatches']);
        $this->assertSame([12000, 12000, 1], [$after['ledger']['refund_cents'], $after['stripe']['refund_cents'], $after['ledger']['refunds']]);
        $this->assertSame(30000 - 12000, $after['expected_balance_cents'], 'Paid minus transferred and refunded');

        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/reconciliation')->assertOk()->assertSee('data-reconciled="yes"', false)
            ->assertSee('Refunds to employers&#039; cards', false)->assertSee('paid minus transferred and refunded');
    }

    public function test_a_merchant_adjustment_is_an_ordinary_matched_transfer(): void
    {
        $this->stripePurchase();
        $payout = MerchantPayout::where('redemption_id', $this->cleared(1250)->id)->sole();
        $request = Correction::create(['type' => Correction::TYPE_MERCHANT_ADJUSTMENT, 'status' => Correction::STATUS_REQUESTED, 'open_key' => 'payout:'.$payout->id,
            'requested_by_role' => Role::Merchant->value, 'reason_category' => 'not_received', 'merchant_payout_id' => $payout->id, 'merchant_id' => $payout->merchant_id]);
        $this->onboard();
        app(CorrectionDecisions::class)->approveMerchantAdjustment($request, $this->admin(), 'Never sent.');
        $this->stripeHasPayments();

        $report = $this->report();
        $this->assertSame([], $report['mismatches']);
        $this->assertSame([1250, 1250], [$report['ledger']['transfer_cents'], $report['stripe']['transfer_cents']]);
    }

    public function test_a_refund_stripe_does_not_have_is_a_mismatch(): void
    {
        $payout = $this->refundedRedemption(450);
        $this->postWebhook($this->reversedEvent($payout))->assertOk();
        $this->stripeHasPayments();
        $this->stripe->transfers[0]['reversals']['data'] = []; // Stripe "lost" it

        $this->assertTrue(collect($this->report()['mismatches'])->contains(fn ($m) => str_starts_with($m, 'Local Goods recorded refund reversal') && str_contains($m, 'no such reversal')));
    }

    public function test_simulated_refunds_are_counted_not_checked(): void
    {
        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($this->cleared(1000)), $this->admin(), 'Refund.', amountCents: 300);

        $report = $this->report();
        $this->assertSame([], $report['mismatches']);
        $this->assertSame([1, 300], [$report['simulated']['refunds'], $report['simulated']['refund_cents']]);
    }

    public function test_another_environments_refunds_are_listed_but_never_counted(): void
    {
        $this->stripe->refunds[] = ['id' => 're_test_local', 'object' => 'refund', 'amount' => 700, 'status' => 'succeeded', 'currency' => 'usd',
            'metadata' => ['lg_correction_key' => 'lg-correction-local', 'lg_env' => 'local', 'lg_instance' => 'abcd1234']];

        $report = $this->report();
        $this->assertSame([], $report['mismatches']);
        $this->assertSame([0, 1, 700], [$report['stripe']['refund_cents'], $report['other']['refunds'], $report['other']['refund_cents']]);
        $this->assertContains('A $7.00 refund (re_test_local) made by another environment (local · abcd1234).', $report['other']['lines']);
    }
}
