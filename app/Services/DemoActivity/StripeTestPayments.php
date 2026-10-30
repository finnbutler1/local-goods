<?php

namespace App\Services\DemoActivity;

use App\Models\CreditPurchase;
use App\Services\Payments\StripeGateway;
use App\Support\StripeEnvironment;
use App\Support\StripeMode;
use Illuminate\Support\Str;
use LogicException;

/**
 * Pays a pending purchase for the demo activity generator (ADR 0032) with
 * Stripe's test card, because embedded Checkout needs a person at a
 * browser. The PaymentIntent carries exactly the metadata Checkout gives
 * its payment (purchase token, reference, id, environment tag) plus
 * lg_demo_activity, so the same signed payment_intent.succeeded webhook
 * marks the purchase paid and reconciliation counts it the same way. This
 * writes nothing to the ledger. Test mode only, checked twice.
 */
class StripeTestPayments
{
    public const TEST_CARD = 'pm_card_visa';

    public function __construct(private StripeGateway $stripe) {}

    public static function isTestSecret(?string $secret): bool
    {
        return is_string($secret) && (str_starts_with($secret, 'sk_test_') || str_starts_with($secret, 'rk_test_'));
    }

    /** @return array<string, mixed> the PaymentIntent Stripe returned */
    public function pay(CreditPurchase $purchase): array
    {
        if (! StripeMode::active() || ! self::isTestSecret(config('services.stripe.secret'))) {
            throw new LogicException('Test-card payments only run in Stripe test mode with a test secret key.');
        }

        if (! $purchase->isPending()) {
            throw new LogicException('Only a pending purchase can be paid.');
        }

        if ($purchase->payment_token === null) {
            $purchase->forceFill(['payment_token' => (string) Str::uuid()])->save();
        }

        return $this->stripe->createTestPayment([
            'amount' => $purchase->totalCents(),
            'currency' => config('services.stripe.currency'),
            'payment_method' => self::TEST_CARD,
            // No payment_method_types: this API version (2026-09-30.endive) refuses
            // it. Confirming at once with automatic methods needs either a
            // return_url or no redirects; a test card never redirects.
            'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
            'confirm' => true,
            'transfer_group' => $purchase->reference,
            'description' => 'Local Goods demo activity: credits '.$purchase->reference.' (test mode)',
            'metadata' => [
                'lg_purchase_token' => $purchase->payment_token,
                'lg_purchase_reference' => $purchase->reference,
                'lg_purchase_id' => (string) $purchase->id,
                'lg_demo_activity' => '1',
            ] + StripeEnvironment::tag(),
        ], 'lg-test-payment-'.$purchase->payment_token);
    }
}
