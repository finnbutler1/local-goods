<?php

namespace App\Services\Payments;

use App\Models\BidProgramFeePayment;
use App\Support\AppUrl;
use App\Support\StripeEnvironment;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stripe's hosted Checkout (test mode) for a pending BID program fee
 * payment (ADR 0044): the BID admin is sent to Stripe's page and back. The
 * same shape as StripeCheckout (start, close, restart). Writes nothing to
 * the ledger: the row stays pending until a verified webhook says Stripe
 * has the money (StripeWebhookHandler → BidProgramFees::markPaidByStripe).
 *
 * The amount is the row's, which came from the district's tier. An open
 * Checkout is reused (two tabs get the same one); an expired one is
 * replaced with a new token; a complete one means a payment is on its way.
 */
class BidProgramFeeCheckout
{
    /** Shown under Stripe's Pay button. No figures. */
    public const MESSAGE = 'The program fee is Local Goods\' own revenue, not customer funds, and can\'t be refunded in this version. Stripe test mode: no real money.';

    public function __construct(private StripeGateway $stripe) {}

    /** The Stripe page to send the BID admin to. */
    public function start(BidProgramFeePayment $payment): string
    {
        if (! $payment->isPending()) {
            throw new InvalidArgumentException('This program year is already paid.');
        }

        if ($payment->stripe_checkout_session_id !== null) {
            $session = $this->stripe->retrieveCheckoutSession($payment->stripe_checkout_session_id);
            if (($session['status'] ?? null) === 'open' && isset($session['url'])) {
                return $session['url'];
            }
            if (! $this->restart($payment)) {
                throw new InvalidArgumentException('Stripe already has the payment for this program year; it shows as paid once Stripe confirms it.');
            }
        }

        $metadata = [
            'lg_bid_fee_token' => $payment->payment_token,
            'lg_bid_fee_id' => (string) $payment->id,
            'lg_district_id' => (string) $payment->district_id,
            'lg_program_year' => (string) $payment->program_year,
        ] + StripeEnvironment::tag();

        $name = 'Local Goods BID program fee, program year '.$payment->program_year.' ('.$payment->tier.' tier, '
            .$payment->period_starts_on->format('M j, Y').' to '.$payment->period_ends_on->format('M j, Y').')';

        $session = $this->stripe->createCheckoutSession([
            // No payment_method_types: which methods appear (card, Apple Pay,
            // Google Pay) is set in the Stripe Dashboard, as for credits.
            'mode' => 'payment',
            'client_reference_id' => 'bid-fee-'.$payment->id,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => $payment->currency, 'unit_amount' => $payment->amount_cents, 'product_data' => ['name' => $name]],
            ]],
            'payment_intent_data' => [
                'description' => $name.' (test mode), Local Goods revenue',
                'metadata' => $metadata,
            ],
            'metadata' => $metadata,
            'custom_text' => ['submit' => ['message' => self::MESSAGE]],
            'expires_at' => now()->addSeconds(StripeCheckout::OPEN_FOR_SECONDS)->getTimestamp(),
            'success_url' => AppUrl::route('bid-admin.program-fee', ['checkout' => 'returned']),
            'cancel_url' => AppUrl::route('bid-admin.program-fee', ['checkout' => 'cancelled']),
        ], 'lg-bid-fee-'.$payment->payment_token);

        $payment->forceFill(['stripe_checkout_session_id' => $session['id']])->save();

        return $session['url'];
    }

    /** As StripeCheckout::close: true when nothing can be paid through the current Checkout any more. */
    public function close(BidProgramFeePayment $payment): bool
    {
        if ($payment->stripe_checkout_session_id === null) {
            return true;
        }

        $status = $this->stripe->retrieveCheckoutSession($payment->stripe_checkout_session_id)['status'] ?? null;

        if ($status === 'open') {
            $this->stripe->expireCheckoutSession($payment->stripe_checkout_session_id);

            return true;
        }

        return $status === 'expired';
    }

    /** A new token once the old Checkout can't take a payment. False when a payment is on its way. */
    public function restart(BidProgramFeePayment $payment): bool
    {
        if (! $this->close($payment)) {
            return false;
        }

        $payment->forceFill(['payment_token' => (string) Str::uuid(), 'stripe_checkout_session_id' => null, 'payment_error' => null])->save();

        return true;
    }
}
