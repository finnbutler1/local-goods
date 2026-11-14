<?php

namespace App\Services\Sandbox;

use App\Models\CreditPurchase;
use App\Services\CreditLedger;
use App\Services\Payments\StripeCheckout;
use App\Services\Payments\StripeGatewayException;
use App\Support\AppUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Hosted Stripe Checkout for Buy credits inside a sandbox (ADR 0045
 * addendum), on the separate sandbox Stripe test account (SandboxStripe).
 *
 * No webhook: when the employer comes back, confirm() reads the Checkout
 * Session we saved on the purchase and records the purchase in this
 * sandbox's own database, through CreditLedger::markPurchasePaidByStripe,
 * only if Stripe says it is paid, for exactly the expected amount and
 * currency, with this sandbox's and this purchase's metadata. That method
 * only changes a pending purchase, so a reload or a second return records
 * nothing more. Everything else Stripe-related in a sandbox stays as before
 * (StripeMode is simulated there: payouts, refunds, balances never call Stripe).
 */
class SandboxCheckout
{
    public const RECORDED = 'recorded';

    public const ALREADY_PAID = 'already_paid';

    public const CANCELLED = 'cancelled';

    public const NOT_PAID = 'not_paid';

    public const MISMATCH = 'mismatch';

    public const UNREACHABLE = 'unreachable';

    /** Per-sandbox count of Checkout Sessions opened, in the sandbox's own cache (inside its file). */
    public const BUDGET_KEY = 'sandbox.stripe_checkouts';

    /** How long a Checkout stays open (Stripe's minimum is 30 minutes). */
    public const OPEN_FOR_SECONDS = 3600;

    public function __construct(private CreditLedger $ledger) {}

    public static function applies(): bool
    {
        return SandboxStripe::available();
    }

    public static function checkoutsLeft(): int
    {
        return max(0, (int) config('sandbox.stripe.checkouts_per_sandbox') - (int) Cache::get(self::BUDGET_KEY, 0));
    }

    /**
     * The Stripe page to send the employer to, or null when Stripe already
     * has the payment (then confirm() it instead). Throws
     * SandboxCheckoutUnavailable when this sandbox has used all its
     * Checkouts, StripeGatewayException when Stripe can't be asked.
     */
    public function start(CreditPurchase $purchase): ?string
    {
        $client = SandboxStripe::client() ?? throw new LogicException('Sandbox Stripe Checkout is off.');

        if (! $purchase->isPending()) {
            throw new InvalidArgumentException($purchase->isCancelled() ? 'That purchase was cancelled.' : 'That purchase is already paid.');
        }

        if ($purchase->stripe_checkout_session_id !== null) {
            $session = $client->retrieveCheckoutSession($purchase->stripe_checkout_session_id);
            if (($session['status'] ?? null) === 'open' && is_string($session['url'] ?? null)) {
                return $session['url'];
            }
            if (($session['status'] ?? null) !== 'expired') {
                return null; // complete: Stripe has the payment
            }
        }

        if (! $this->takeOne()) {
            throw new SandboxCheckoutUnavailable('This sandbox has opened its '.config('sandbox.stripe.checkouts_per_sandbox').' Stripe Checkouts');
        }

        $purchase->forceFill(['payment_token' => (string) Str::uuid(), 'stripe_checkout_session_id' => null, 'payment_error' => null])->save();
        $metadata = $this->metadata($purchase);

        $session = $client->createCheckoutSession([
            'mode' => 'payment',
            'client_reference_id' => $purchase->reference,
            'line_items' => StripeCheckout::lineItems($purchase),
            'payment_intent_data' => [
                'description' => 'Local Goods sandbox credits '.$purchase->reference.' (test mode, separate sandbox account)',
                'metadata' => $metadata,
            ],
            'metadata' => $metadata,
            'custom_text' => ['submit' => ['message' => StripeCheckout::submitMessage($purchase)]],
            'expires_at' => now()->addSeconds(self::OPEN_FOR_SECONDS)->getTimestamp(),
            // Stripe fills in {CHECKOUT_SESSION_ID}; confirm() still reads the id saved below, never trusts this one alone.
            'success_url' => AppUrl::route('employer.purchases.sandbox-return', ['purchase' => $purchase]).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => AppUrl::route('employer.dashboard', ['checkout' => 'left', 'purchase' => $purchase->reference]).'#buy',
        ], 'lg-sandbox-checkout-'.$purchase->payment_token);

        if (! is_string($session['id'] ?? null) || ! is_string($session['url'] ?? null)) {
            throw new StripeGatewayException('Stripe sent a reply Local Goods could not read. Nothing was charged.', definite: false);
        }

        $purchase->forceFill(['stripe_checkout_session_id' => $session['id']])->save();

        return $session['url'];
    }

    /**
     * Back from Stripe: records the purchase once, only when everything
     * matches. $sessionIdFromUrl must equal the session saved on the
     * purchase; the session read from Stripe is always the saved one.
     */
    public function confirm(CreditPurchase $purchase, ?string $sessionIdFromUrl = null, bool $checkUrl = true): string
    {
        $client = SandboxStripe::client() ?? throw new LogicException('Sandbox Stripe Checkout is off.');
        $purchase->refresh();

        if ($purchase->isCancelled()) {
            return self::CANCELLED;
        }
        if (! $purchase->isPending()) {
            return self::ALREADY_PAID;
        }

        $saved = $purchase->stripe_checkout_session_id;
        if ($saved === null || ($checkUrl && (! is_string($sessionIdFromUrl) || ! hash_equals($saved, $sessionIdFromUrl)))) {
            return self::MISMATCH;
        }

        try {
            $session = $client->retrieveCheckoutSession($saved);
        } catch (StripeGatewayException) {
            return self::UNREACHABLE;
        }

        if (($session['payment_status'] ?? null) !== 'paid') {
            return self::NOT_PAID;
        }

        $metadata = (array) ($session['metadata'] ?? []);
        $amount = $session['amount_total'] ?? null;
        $currency = $session['currency'] ?? null;
        $paymentIntent = $session['payment_intent'] ?? null;
        $paymentIntent = is_array($paymentIntent) ? ($paymentIntent['id'] ?? null) : $paymentIntent;

        $matches = ($session['id'] ?? null) === $saved
            && is_int($amount) && $amount === $purchase->totalCents()
            && is_string($currency) && strtolower($currency) === config('services.stripe.currency')
            && is_string($paymentIntent) && $paymentIntent !== ''
            && self::same($metadata['lg_sandbox'] ?? null, SandboxStripe::tag())
            && self::same($metadata['lg_purchase_token'] ?? null, (string) $purchase->payment_token)
            && self::same($metadata['lg_purchase_reference'] ?? null, $purchase->reference);

        if (! $matches) {
            return self::MISMATCH;
        }

        return $this->ledger->markPurchasePaidByStripe($purchase, $paymentIntent, null, $amount, $currency)
            ? self::RECORDED
            : ($purchase->refresh()->isCancelled() ? self::CANCELLED : self::ALREADY_PAID);
    }

    /**
     * Before Cancel: true when nothing can be paid at Stripe any more
     * (expired now, already expired, or never opened); false when Stripe
     * says it is complete. Throws StripeGatewayException when Stripe can't be asked.
     */
    public function close(CreditPurchase $purchase): bool
    {
        $client = SandboxStripe::client() ?? throw new LogicException('Sandbox Stripe Checkout is off.');

        if ($purchase->stripe_checkout_session_id === null) {
            return true;
        }

        $status = $client->retrieveCheckoutSession($purchase->stripe_checkout_session_id)['status'] ?? null;
        if ($status === 'open') {
            $client->expireCheckoutSession($purchase->stripe_checkout_session_id);

            return true;
        }

        return $status === 'expired';
    }

    /** @return array<string, string> */
    private function metadata(CreditPurchase $purchase): array
    {
        // No lg_env / lg_instance: nothing from the sandbox account may look like a live-site object.
        return [
            'lg_sandbox' => SandboxStripe::tag(),
            'lg_purchase_token' => (string) $purchase->payment_token,
            'lg_purchase_reference' => $purchase->reference,
        ];
    }

    private function takeOne(): bool
    {
        // The database store won't increment a missing key, so make it first. Outlives the sandbox (24 hours).
        Cache::add(self::BUDGET_KEY, 0, now()->addDays(2));

        return (int) Cache::increment(self::BUDGET_KEY) <= (int) config('sandbox.stripe.checkouts_per_sandbox');
    }

    private static function same(mixed $given, string $expected): bool
    {
        return is_string($given) && $expected !== '' && hash_equals($expected, $given);
    }
}
