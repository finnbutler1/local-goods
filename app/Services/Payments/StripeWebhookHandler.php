<?php

namespace App\Services\Payments;

use App\Models\BidProgramFeePayment;
use App\Models\CorrectionAction;
use App\Models\CreditPurchase;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\StripeEvent;
use App\Services\Corrections\CorrectionProcessor;
use App\Services\CreditLedger;
use App\Support\StripeEnvironment;
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
 *
 * Events about an object tagged for another environment (local and
 * production can share one Stripe sandbox) are answered and ignored before
 * anything is written. Untagged objects (made before tagging) go through,
 * since each handler only finds records by this database's own ids.
 */
class StripeWebhookHandler
{
    public function __construct(
        private CreditLedger $ledger,
        private StripeOnboarding $onboarding,
        private MerchantPayouts $payouts,
        private CorrectionProcessor $corrections,
        private BidProgramFees $bidFees,
    ) {}

    /** @return string a short outcome, for the log and stripe_events */
    public function handle(array $event): string
    {
        $after = null;

        if (StripeEnvironment::of($event['data']['object']['metadata'] ?? null) === StripeEnvironment::OTHER) {
            Log::info('Stripe webhook ignored: other environment', ['event' => $event['id'], 'type' => $event['type']]);

            return 'ignored: other environment';
        }

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
            'refund.created', 'refund.updated', 'refund.failed' => $this->corrections->confirmRefund($object, $at) ?? 'ignored: not a Local Goods refund',
            default => 'ignored: not an event Local Goods uses',
        };
    }

    private function purchaseFor(array $paymentIntent): ?CreditPurchase
    {
        $token = $paymentIntent['metadata']['lg_purchase_token'] ?? null;

        return $token ? CreditPurchase::where('payment_token', $token)->first() : null;
    }

    /** The BID program fee payment (ADR 0044) a PaymentIntent is for, by its current token. */
    private function bidFeeFor(array $object): ?BidProgramFeePayment
    {
        $token = $object['metadata']['lg_bid_fee_token'] ?? null;

        return is_string($token) && $token !== '' ? BidProgramFeePayment::where('payment_token', $token)->first() : null;
    }

    private function paymentSucceeded(array $pi, Carbon $at): string
    {
        if (isset($pi['metadata']['lg_bid_fee_token'])) {
            return $this->bidFeePaid($pi, $at);
        }

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

        if (! $paid && $purchase->isCancelled()) {
            Log::warning('Stripe payment for a cancelled purchase', ['purchase' => $purchase->reference, 'payment_intent' => $pi['id']]);

            return 'refused: '.$purchase->reference.' was cancelled, so '.$pi['id'].' is not credited (see reconciliation)';
        }

        return $paid
            ? 'paid: '.$purchase->reference
            : ($purchase->stripe_payment_intent_id === $pi['id']
                ? 'already paid: '.$purchase->reference
                : 'already paid by another payment: '.$pi['id'].' not credited (see reconciliation)');
    }

    /**
     * The BID program fee (ADR 0044): recorded only for the row's current
     * token, with amount, currency and metadata matching; otherwise nothing is
     * written and reconciliation shows the payment as one Local Goods has no
     * record of.
     */
    private function bidFeePaid(array $pi, Carbon $at): string
    {
        $payment = $this->bidFeeFor($pi);

        if ($payment === null) {
            Log::warning('Stripe payment for an unknown BID program fee token', ['payment_intent' => $pi['id'] ?? null]);

            return 'ignored: not a current BID program fee payment (see reconciliation)';
        }

        if (($pi['status'] ?? null) !== 'succeeded') {
            return 'ignored: payment not succeeded';
        }

        try {
            $paid = $this->bidFees->markPaidByStripe($payment, $pi, $at);
        } catch (InvalidArgumentException $e) {
            Log::warning('Stripe BID program fee payment refused', ['bid_fee_payment' => $payment->id, 'payment_intent' => $pi['id'] ?? null]);

            return 'refused: '.$e->getMessage();
        }

        return $paid
            ? 'paid: BID program fee #'.$payment->id
            : ($payment->stripe_payment_intent_id === $pi['id']
                ? 'already paid: BID program fee #'.$payment->id
                : 'already paid by another payment: '.$pi['id'].' not recorded (see reconciliation)');
    }

    private function paymentFailed(array $pi): string
    {
        if (isset($pi['metadata']['lg_bid_fee_token'])) {
            $payment = $this->bidFeeFor($pi);
            if ($payment === null || ! $payment->isPending()) {
                return 'ignored: no pending BID program fee payment';
            }
            $payment->forceFill(['payment_error' => Str::limit((string) ($pi['last_payment_error']['message'] ?? 'The card payment failed.'), 250)])->save();

            return 'payment failed: BID program fee #'.$payment->id.' stays due';
        }

        $purchase = $this->purchaseFor($pi);

        if ($purchase === null || ! $purchase->isPending()) {
            return 'ignored: no pending Local Goods purchase';
        }

        $purchase->update(['payment_error' => Str::limit((string) ($pi['last_payment_error']['message'] ?? 'The card payment failed.'), 250)]);

        return 'payment failed: '.$purchase->reference.' stays pending';
    }

    private function checkoutExpired(array $session): string
    {
        $fee = BidProgramFeePayment::where('stripe_checkout_session_id', $session['id'] ?? '')->first();
        if ($fee !== null) {
            if (! $fee->isPending()) {
                return 'ignored: BID program fee #'.$fee->id.' is not pending';
            }
            // A fresh token, so the next "Pay this year's program fee" opens a new Checkout. The year stays due.
            $fee->forceFill(['payment_token' => (string) Str::uuid(), 'stripe_checkout_session_id' => null])->save();

            return 'checkout expired: BID program fee #'.$fee->id.' stays due';
        }

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
        $recorded = $this->ledger->recordStripeTransfer($payout, $transfer['id'], $at);
        // A merchant adjustment waiting on this row is done (ADR 0028).
        $this->corrections->payoutSent($payout->refresh(), $at);

        return $recorded
            ? 'transfer recorded from webhook: '.$transfer['id']
            : 'transfer already recorded: '.$transfer['id'];
    }

    /**
     * Stripe lists every reversal on the transfer, with its metadata, and
     * amount_reversed adds up (trial of 2026-10-24, ADR 0028). Refund
     * reversals Local Goods made are recorded first (each once). The old
     * path, "reversed in Stripe, owed again", only runs for reversed money
     * those refunds don't explain, so with no refunds it works exactly as
     * before.
     */
    private function transferReversed(array $transfer, Carbon $at): string
    {
        $payout = $this->payoutFor($transfer);

        if ($payout === null) {
            return 'ignored: not a Local Goods transfer';
        }

        $outcomes = [];
        foreach ($transfer['reversals']['data'] ?? [] as $reversal) {
            if (($outcome = $this->corrections->confirmReversal($reversal, $at)) !== null) {
                $outcomes[] = $outcome;
            }
        }

        $explained = (int) CorrectionAction::where('kind', CorrectionAction::KIND_TRANSFER_REVERSAL)->where('merchant_payout_id', $payout->id)
            ->where('status', CorrectionAction::STATUS_SUCCEEDED)->sum('amount_cents');

        if (! isset($transfer['amount_reversed']) || $transfer['amount_reversed'] > $explained) {
            $outcomes[] = $this->ledger->recordStripeReversal($payout, $at)
                ? 'transfer reversed: '.$transfer['id'].' owed again'
                : 'reversal already recorded: '.$transfer['id'];
        }

        return implode('; ', $outcomes) ?: 'refunds already recorded: '.$transfer['id'];
    }
}
