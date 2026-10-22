<?php

namespace App\Services\Payments;

use App\Models\CreditPurchase;
use App\Support\AppUrl;
use App\Support\StripeEnvironment;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stripe's embedded Checkout (test mode) for a pending purchase: the card
 * form shows inside the employer dashboard. This writes nothing to the
 * ledger: the purchase stays pending until a verified webhook says Stripe
 * has the money (StripeWebhookHandler). The payment's transfer_group is the
 * purchase reference, so the merchant transfers it later funds are grouped
 * with it in Stripe (separate charges and transfers).
 */
class StripeCheckout
{
    /** How long a Checkout stays open; after that Stripe expires it and the webhook makes room for a fresh one. */
    public const OPEN_FOR_SECONDS = 3600;

    public function __construct(private StripeGateway $stripe) {}

    /**
     * The client secret Stripe.js needs to show the card form. Asking again
     * for the same purchase gets the same Checkout (same idempotency key)
     * until restart() gives it a new token.
     */
    public function start(CreditPurchase $purchase): string
    {
        if (! $purchase->isPending()) {
            throw new InvalidArgumentException($purchase->isCancelled() ? 'That purchase was cancelled.' : 'That purchase is already paid.');
        }

        if ($purchase->payment_token === null) {
            $purchase->forceFill(['payment_token' => (string) Str::uuid()])->save();
        }

        $metadata = [
            'lg_purchase_token' => $purchase->payment_token,
            'lg_purchase_reference' => $purchase->reference,
            'lg_purchase_id' => (string) $purchase->id,
        ] + StripeEnvironment::tag();

        $item = fn (int $cents, string $name) => [
            'quantity' => 1,
            'price_data' => ['currency' => config('services.stripe.currency'), 'unit_amount' => $cents, 'product_data' => ['name' => $name]],
        ];
        $lineItems = [$item($purchase->amount_cents, 'Local Goods credits ('.$purchase->reference.')')];
        // Only when the fee is on (ADR 0021); with it off Checkout looks exactly as before.
        if ($purchase->platform_fee_cents > 0) {
            $lineItems[] = $item($purchase->platform_fee_cents, 'Local Goods platform fee ('.$purchase->reference.')');
        }

        $session = $this->stripe->createCheckoutSession([
            // No payment_method_types: Stripe's current API refuses it for
            // Checkout. Which methods appear (card, in test mode) is set in
            // the Stripe Dashboard under Settings → Payment methods.
            'mode' => 'payment',
            'ui_mode' => 'embedded_page',
            'client_reference_id' => $purchase->reference,
            'line_items' => $lineItems,
            'payment_intent_data' => [
                'transfer_group' => $purchase->reference,
                'description' => 'Local Goods credits '.$purchase->reference.' (test mode)',
                'metadata' => $metadata,
            ],
            'metadata' => $metadata,
            'expires_at' => now()->addSeconds(self::OPEN_FOR_SECONDS)->getTimestamp(),
            // APP_URL in production (the CloudFront address), never this request's host.
            'return_url' => AppUrl::route('employer.dashboard', ['checkout' => 'returned', 'purchase' => $purchase->reference]).'#buy',
        ], 'lg-checkout-'.$purchase->payment_token);

        $purchase->forceFill(['stripe_checkout_session_id' => $session['id']])->save();

        return $session['client_secret'];
    }

    /**
     * Makes sure no payment can still arrive through the purchase's current
     * Checkout: expires it if it is open. True when nothing can be paid there
     * any more (just expired, already expired, or never opened); false when
     * Stripe says it is complete, so a payment is on its way and the webhook
     * will confirm it. Throws StripeGatewayException when Stripe can't be
     * asked; then nothing here has changed.
     */
    public function close(CreditPurchase $purchase): bool
    {
        if ($purchase->stripe_checkout_session_id === null) {
            return true;
        }

        $status = $this->stripe->retrieveCheckoutSession($purchase->stripe_checkout_session_id)['status'] ?? null;

        if ($status === 'open') {
            $this->stripe->expireCheckoutSession($purchase->stripe_checkout_session_id);

            return true;
        }

        return $status === 'expired';
    }

    /**
     * "Try again": closes the old Checkout and, only once it can't take a
     * payment any more, gives the purchase a new token so the next start()
     * opens a fresh one. An old Checkout is never left payable against a
     * token Local Goods has forgotten. False when a payment is on its way.
     */
    public function restart(CreditPurchase $purchase): bool
    {
        if (! $this->close($purchase)) {
            return false;
        }

        $purchase->forceFill(['payment_token' => (string) Str::uuid(), 'stripe_checkout_session_id' => null, 'payment_error' => null])->save();

        return true;
    }
}
