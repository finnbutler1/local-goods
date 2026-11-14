<?php

namespace Tests\Concerns;

use App\Models\BidProgramFeePayment;
use App\Models\District;
use App\Support\StripeEnvironment;
use Illuminate\Testing\TestResponse;

/**
 * The BID annual program fee (ADR 0044) in tests: a district with a member
 * count and start date, the switch on, and the PaymentIntent Stripe would
 * send for a payment. Use with UsesFakeStripe.
 */
trait PaysBidProgramFee
{
    /** Golden Triangle, Large tier ($5,000), program year 1 from 2026-10-01 (dates as a test sets them). */
    protected function setUpDistrict(int $members = 6000, string $startsOn = '2026-10-01'): District
    {
        $district = District::default();
        $district->forceFill(['member_businesses' => $members, 'program_starts_on' => $startsOn])->save();

        return $district->refresh();
    }

    protected function switchOnBidFee(): void
    {
        config(['payments.bid_program_fee_enabled' => true]);
    }

    /** The PaymentIntent Stripe sends for this payment, as it is now; $changes override any field. */
    protected function bidFeeIntent(BidProgramFeePayment $payment, array $changes = [], array $metadata = []): array
    {
        static $n = 0;
        $n++;

        return array_replace([
            'id' => 'pi_test_bidfee_'.$n,
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => $payment->amount_cents,
            'amount_received' => $payment->amount_cents,
            'currency' => 'usd',
            'latest_charge' => 'ch_test_bidfee_'.$n,
            'created' => now()->getTimestamp(),
            'metadata' => array_replace([
                'lg_bid_fee_token' => $payment->payment_token,
                'lg_bid_fee_id' => (string) $payment->id,
                'lg_district_id' => (string) $payment->district_id,
                'lg_program_year' => (string) $payment->program_year,
            ] + StripeEnvironment::tag(), $metadata),
        ], $changes);
    }

    /** Stripe says the payment succeeded: a signed webhook, and Stripe "has" the PaymentIntent for reconciliation. */
    protected function payBidFee(BidProgramFeePayment $payment, array $changes = [], array $metadata = []): TestResponse
    {
        $pi = $this->bidFeeIntent($payment->fresh(), $changes, $metadata);
        $this->stripe->paymentIntents[] = $pi;

        return $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $pi));
    }
}
