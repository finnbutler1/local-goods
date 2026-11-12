<?php

namespace App\Services\Payments;

use App\Models\CreditPurchase;
use App\Support\AppUrl;
use App\Support\EmployerPlatformFee;
use App\Support\Money;
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
    /** Shown under Stripe's Pay button (ADR 0034): where the employer's money is held. No figures. */
    public const CUSTOMER_FUNDS_MESSAGE = 'Your money is held in the Local Goods customer funds account (Stripe test mode) and stays yours until your staff spend it. It is never used for Local Goods\' own expenses.';

    /** Added only when the purchase carries the employer platform fee (ADR 0042): that part is not customer funds. */
    public const EMPLOYER_FEE_MESSAGE = 'The platform fee is Local Goods\' own revenue, not credits for your staff, and can\'t be refunded in this version.';

    /** How long a Checkout stays open; after that Stripe expires it and the webhook makes room for a fresh one. */
    public const OPEN_FOR_SECONDS = 3600;

    public function __construct(private StripeGateway $stripe) {}

    /**
     * The client secret Stripe.js needs to show the card form. Asking again
     * for the same purchase (a reload) gets the same Checkout while it is
     * still open; once it has expired, a fresh one with a new token.
     */
    public function start(CreditPurchase $purchase): string
    {
        if (! $purchase->isPending()) {
            throw new InvalidArgumentException($purchase->isCancelled() ? 'That purchase was cancelled.' : 'That purchase is already paid.');
        }

        // Asked again, not asking Stripe with the old key: expires_at differs on
        // every request, and Stripe replays a key only for identical parameters.
        if ($purchase->stripe_checkout_session_id !== null) {
            $session = $this->stripe->retrieveCheckoutSession($purchase->stripe_checkout_session_id);
            if (($session['status'] ?? null) === 'open' && isset($session['client_secret'])) {
                return $session['client_secret'];
            }
            // Expired: restart() gives a new token below. Complete: the payment is on its way.
            if (! $this->restart($purchase)) {
                throw new InvalidArgumentException('Stripe already has the payment for this purchase; the credits arrive once Stripe confirms it.');
            }
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
        // Only on the month's first purchase while the employer platform fee is on (ADR 0042).
        if ($purchase->hasEmployerFee()) {
            $lineItems[] = $item($purchase->employer_fee_cents, self::employerFeeLine($purchase));
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
                'description' => 'Local Goods credits '.$purchase->reference.' (test mode), held as customer funds',
                'metadata' => $metadata,
            ],
            'metadata' => $metadata,
            'custom_text' => ['submit' => ['message' => self::CUSTOMER_FUNDS_MESSAGE.($purchase->hasEmployerFee() ? ' '.self::EMPLOYER_FEE_MESSAGE : '')]],
            'expires_at' => now()->addSeconds(self::OPEN_FOR_SECONDS)->getTimestamp(),
            // APP_URL in production (the CloudFront address), never this request's host.
            'return_url' => AppUrl::route('employer.dashboard', ['checkout' => 'returned', 'purchase' => $purchase->reference]).'#buy',
        ], 'lg-checkout-'.$purchase->payment_token);

        $purchase->forceFill(['stripe_checkout_session_id' => $session['id']])->save();

        return $session['client_secret'];
    }

    /** "Platform fee for November 2026: 38 participating employees × $6.00", the same words on Checkout and the receipt. */
    public static function employerFeeLine(CreditPurchase $purchase): string
    {
        $n = (int) $purchase->employer_fee_employees;

        return 'Platform fee for '.EmployerPlatformFee::monthLabel($purchase->employer_fee_month).': '.$n.' participating '
            .($n === 1 ? 'employee' : 'employees').' × '.Money::format((int) $purchase->employer_fee_unit_cents);
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
