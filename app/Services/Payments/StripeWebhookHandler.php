<?php

namespace App\Services\Payments;

use App\Models\CreditPurchase;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\StripeEvent;
use App\Services\CreditLedger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Handles a Stripe event whose signature StripeWebhookController has
 * already verified. Every event is handled at most once: its id goes into
 * stripe_events in the same transaction as its changes, so a replay (or
 * Stripe retrying) finds it there and changes nothing. Each handler is
 * also guarded by state (only a pending purchase can be paid, only a sent
 * transfer reversed), so even a new event about old news writes nothing.
 * Transfers are sent after the transaction, never inside it.
 */
class StripeWebhookHandler
{
    public function __construct(
        private CreditLedger $ledger,
        private StripeOnboarding $onboarding,
        private MerchantPayouts $payouts,
    ) {}

    /** @return string a short outcome, for the log and stripe_events */
    public function handle(array $event): string
    {
        $after = null;

        try {
            $outcome = DB::transaction(function () use ($event, &$after) {
                StripeEvent::create(['stripe_event_id' => $event['id'], 'type' => $event['type'], 'processed_at' => now()]);

                $outcome = $this->dispatch($event, $after);
                StripeEvent::where('stripe_event_id', $event['id'])->update(['outcome' => Str::limit($outcome, 250)]);

                return $outcome;
            });
        } catch (UniqueConstraintViolationException) {
            return 'duplicate: already handled, nothing changed';
        }

        if ($after !== null) {
            $after();
        }

        Log::info('Stripe webhook handled', ['event' => $event['id'], 'type' => $event['type'], 'outcome' => $outcome]);

        return $outcome;
    }

    private function dispatch(array $event, ?callable &$after): string
    {
        $object = $event['data']['object'] ?? [];
        $at = isset($event['created']) ? Carbon::createFromTimestamp($event['created']) : now();

        return match ($event['type']) {
            'payment_intent.succeeded' => $this->paymentSucceeded($object, $at),
            'payment_intent.payment_failed' => $this->paymentFailed($object),
            'checkout.session.expired' => $this->checkoutExpired($object),
            'account.updated' => $this->accountUpdated($object, $after),
            'transfer.created' => $this->transferCreated($object, $at),
            'transfer.reversed' => $this->transferReversed($object, $at),
            default => 'ignored: not an event Local Goods uses',
        };
    }

    private function purchaseFor(array $paymentIntent): ?CreditPurchase
    {
        $token = $paymentIntent['metadata']['lg_purchase_token'] ?? null;

        return $token ? CreditPurchase::where('payment_token', $token)->first() : null;
    }

    private function paymentSucceeded(array $pi, Carbon $at): string
    {
        $purchase = $this->purchaseFor($pi);

        if ($purchase === null) {
            return 'ignored: not a Local Goods purchase';
        }

        if (($pi['status'] ?? null) !== 'succeeded') {
            return 'ignored: payment not succeeded';
        }

        $charge = is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge']['id'] : ($pi['latest_charge'] ?? null);

        try {
            $paid = $this->ledger->markPurchasePaidByStripe($purchase, $pi['id'], $charge, (int) ($pi['amount_received'] ?? $pi['amount']), (string) $pi['currency'], $at);
        } catch (InvalidArgumentException $e) {
            Log::warning('Stripe payment refused', ['purchase' => $purchase->reference, 'payment_intent' => $pi['id']]);

            return 'refused: '.$e->getMessage();
        }

        return $paid
            ? 'paid: '.$purchase->reference
            : ($purchase->stripe_payment_intent_id === $pi['id']
                ? 'already paid: '.$purchase->reference
                : 'already paid by another payment: '.$pi['id'].' not credited (see reconciliation)');
    }

    private function paymentFailed(array $pi): string
    {
        $purchase = $this->purchaseFor($pi);

        if ($purchase === null || ! $purchase->isPending()) {
            return 'ignored: no pending Local Goods purchase';
        }

        $purchase->update(['payment_error' => Str::limit((string) ($pi['last_payment_error']['message'] ?? 'The card payment failed.'), 250)]);

        return 'payment failed: '.$purchase->reference.' stays pending';
    }

    private function checkoutExpired(array $session): string
    {
        $purchase = CreditPurchase::where('stripe_checkout_session_id', $session['id'] ?? '')->first();

        if ($purchase === null || ! $purchase->isPending()) {
            return 'ignored: no pending Local Goods purchase';
        }

        // A fresh token, so the next "Pay with Stripe" opens a new Checkout.
        $purchase->update(['payment_token' => (string) Str::uuid(), 'stripe_checkout_session_id' => null]);

        return 'checkout expired: '.$purchase->reference.' stays pending';
    }

    private function accountUpdated(array $account, ?callable &$after): string
    {
        $merchant = Merchant::where('stripe_account_id', $account['id'] ?? '')->first();

        if ($merchant === null) {
            return 'ignored: not a Local Goods merchant';
        }

        $justOnboarded = $this->onboarding->apply($merchant, $account);

        if ($merchant->isStripeOnboarded()) {
            $after = fn () => $this->payouts->sendSafely($merchant);
        }

        return $justOnboarded ? 'onboarded: '.$merchant->name : 'account synced: '.$merchant->name;
    }

    private function payoutFor(array $transfer): ?MerchantPayout
    {
        return MerchantPayout::stripe()
            ->where(fn ($q) => $q->where('stripe_transfer_id', $transfer['id'] ?? '')
                ->orWhere('idempotency_key', $transfer['metadata']['lg_payout_key'] ?? '-'))
            ->first();
    }

    private function transferCreated(array $transfer, Carbon $at): string
    {
        $payout = $this->payoutFor($transfer);

        if ($payout === null) {
            return 'ignored: not a Local Goods transfer';
        }

        // Normally already recorded when Stripe answered; this catches a
        // transfer whose answer we never got (a timeout).
        return $this->ledger->recordStripeTransfer($payout, $transfer['id'], $at)
            ? 'transfer recorded from webhook: '.$transfer['id']
            : 'transfer already recorded: '.$transfer['id'];
    }

    private function transferReversed(array $transfer, Carbon $at): string
    {
        $payout = $this->payoutFor($transfer);

        if ($payout === null) {
            return 'ignored: not a Local Goods transfer';
        }

        return $this->ledger->recordStripeReversal($payout, $at)
            ? 'transfer reversed: '.$transfer['id'].' owed again'
            : 'reversal already recorded: '.$transfer['id'];
    }
}
