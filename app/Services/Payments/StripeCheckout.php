<?php

namespace App\Services\Payments;

use App\Models\CreditPurchase;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Starts a Stripe Checkout payment (test mode) for a pending purchase. This
 * writes nothing to the ledger: the purchase stays pending until a verified
 * webhook says Stripe has the money (StripeWebhookHandler). The payment's
 * transfer_group is the purchase reference, so the merchant transfers it
 * later funds are grouped with it in Stripe (separate charges and transfers).
 */
class StripeCheckout
{
    public function __construct(private StripeGateway $stripe) {}

    /** @return string the Checkout page to send the employer to */
    public function start(CreditPurchase $purchase): string
    {
        if (! $purchase->isPending()) {
            throw new InvalidArgumentException('That purchase is already paid.');
        }

        if ($purchase->payment_token === null) {
            $purchase->forceFill(['payment_token' => (string) Str::uuid()])->save();
        }

        $metadata = [
            'lg_purchase_token' => $purchase->payment_token,
            'lg_purchase_reference' => $purchase->reference,
        ];
        $dashboard = route('employer.dashboard');

        $session = $this->stripe->createCheckoutSession([
            // No payment_method_types: Stripe's current API refuses it for
            // Checkout. Which methods appear (card, in test mode) is set in
            // the Stripe Dashboard under Settings → Payment methods.
            'mode' => 'payment',
            'client_reference_id' => $purchase->reference,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => config('services.stripe.currency'),
                    'unit_amount' => $purchase->amount_cents,
                    'product_data' => ['name' => 'Local Goods credits ('.$purchase->reference.')'],
                ],
            ]],
            'payment_intent_data' => [
                'transfer_group' => $purchase->reference,
                'description' => 'Local Goods credits '.$purchase->reference.' (test mode)',
                'metadata' => $metadata,
            ],
            'metadata' => $metadata,
            'success_url' => $dashboard.'?checkout=returned&purchase='.urlencode($purchase->reference).'#credits',
            'cancel_url' => $dashboard.'?checkout=cancelled&purchase='.urlencode($purchase->reference).'#credits',
        ], 'lg-checkout-'.$purchase->payment_token);

        $purchase->forceFill(['stripe_checkout_session_id' => $session['id']])->save();

        return $session['url'];
    }
}
