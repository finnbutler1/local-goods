<?php

namespace App\Services\Corrections;

use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\MerchantPayout;
use App\Services\CreditLedger;
use App\Services\Payments\MerchantPayouts;
use App\Services\Payments\StripeGateway;
use App\Services\Payments\StripeGatewayException;
use App\Support\Money;
use App\Support\StripeEnvironment;
use App\Support\StripeMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a correction's Stripe actions (ADR 0028), after the approval has
 * committed, never inside it: a Stripe outage must not undo a decision.
 * Nothing here throws; a refusal is written on the action and the
 * correction (failed, shown in plain English, retried safely).
 *
 * Stripe's reply only records the Stripe id. The ledger lines are written
 * when Stripe's signed webhook confirms (confirmReversal, confirmRefund),
 * the same rule as Stripe-paid purchases, in the webhook's transaction.
 */
class CorrectionProcessor
{
    public function __construct(private StripeGateway $stripe, private CreditLedger $ledger, private MerchantPayouts $payouts) {}

    /**
     * One key per action and attempt: this server, the correction, the
     * action, when it was made, and how many times Stripe definitely refused
     * it. Retrying after a timeout reuses the key, so Stripe acts once.
     */
    public static function idempotencyKey(CorrectionAction $action): string
    {
        return 'lg-correction-'.StripeEnvironment::instance().'-c'.$action->correction_id.'-a'.$action->id
            .'-'.$action->created_at->getTimestamp().'-'.$action->attempts;
    }

    public function run(Correction $correction): void
    {
        // Like MerchantPayouts::send: with Stripe test mode off on this server, Stripe steps wait (nothing is lost).
        if (! StripeMode::active()) {
            $correction->actions()->whereIn('status', [CorrectionAction::STATUS_PENDING, CorrectionAction::STATUS_FAILED])
                ->where('kind', '!=', CorrectionAction::KIND_SIMULATED)
                ->update(['failure_reason' => 'Stripe test mode is off on this server, so this has not been sent to Stripe yet.']);
            self::syncStatus($correction);

            return;
        }

        foreach ($correction->actions()->whereIn('status', [CorrectionAction::STATUS_PENDING, CorrectionAction::STATUS_PROCESSING, CorrectionAction::STATUS_FAILED])->get() as $action) {
            if ($action->kind === CorrectionAction::KIND_SIMULATED) {
                continue;
            }

            try {
                $action->kind === CorrectionAction::KIND_ADJUSTMENT_TRANSFER ? $this->sendOwedPayout($action) : $this->send($action);
            } catch (Throwable $e) {
                Log::error('Correction step failed; nothing was recorded', ['correction' => $correction->id, 'action' => $action->id, 'type' => class_basename($e)]);
                $this->fail($action, 'Local Goods could not finish this step. Nothing was recorded; retry it.', definite: false);
            }
        }

        self::syncStatus($correction);
    }

    private function send(CorrectionAction $action): void
    {
        // Claim it for this attempt. An action whose outcome we never learned keeps its key.
        $key = DB::transaction(function () use ($action) {
            $fresh = CorrectionAction::whereKey($action->id)->lockForUpdate()->first();

            if ($fresh->status === CorrectionAction::STATUS_SUCCEEDED || ($fresh->status === CorrectionAction::STATUS_PROCESSING && $fresh->stripe_id !== null)) {
                return null;
            }

            $key = $fresh->status === CorrectionAction::STATUS_PROCESSING && $fresh->idempotency_key !== null
                ? $fresh->idempotency_key
                : self::idempotencyKey($fresh);
            $fresh->update(['status' => CorrectionAction::STATUS_PROCESSING, 'idempotency_key' => $key]);

            return $key;
        });

        if ($key === null) {
            return;
        }

        $action->refresh();
        $metadata = ['lg_correction_key' => $key, 'lg_correction_id' => (string) $action->correction_id] + StripeEnvironment::tag();

        try {
            $made = match ($action->kind) {
                CorrectionAction::KIND_TRANSFER_REVERSAL => $this->stripe->reverseTransfer($action->payout->stripe_transfer_id, [
                    'amount' => $action->amount_cents,
                    'description' => 'Local Goods: refund on redemption #'.$action->correction->redemption_id.' (test mode)',
                    'metadata' => $metadata + ['lg_redemption_id' => (string) $action->correction->redemption_id],
                ], $key),
                // Credits only, never more than is left of the payment (CorrectionDecisions checked).
                CorrectionAction::KIND_CARD_REFUND => $this->stripe->createRefund([
                    'payment_intent' => $action->creditPurchase->stripe_payment_intent_id,
                    'amount' => $action->amount_cents,
                    'reason' => 'requested_by_customer',
                    'metadata' => $metadata + ['lg_purchase_reference' => $action->creditPurchase->reference],
                ], $key),
            };
        } catch (StripeGatewayException $e) {
            $this->fail($action, $e->getMessage(), $e->definite);

            return;
        }

        $action->update(['stripe_id' => $made['id'], 'failure_reason' => null]);
    }

    /**
     * A merchant adjustment: send its owed payout row through the existing
     * path (MerchantPayouts::send, the TransferGate, the row's own
     * idempotency key), then reflect what happened to the row.
     */
    private function sendOwedPayout(CorrectionAction $action): void
    {
        $action->update(['status' => CorrectionAction::STATUS_PROCESSING]);
        $this->payouts->send($action->payout->merchant);
        $payout = $action->payout->fresh();

        match ($payout->status) {
            MerchantPayout::STATUS_SENT => DB::transaction(fn () => $this->payoutSent($payout, now())),
            MerchantPayout::STATUS_OWED => $action->update(['failure_reason' => 'Waiting for the merchant to finish Stripe onboarding; it is sent as soon as they do.']),
            MerchantPayout::STATUS_FAILED => $action->update(['status' => CorrectionAction::STATUS_FAILED, 'failure_reason' => $payout->failure_reason]),
            default => $action->update(['failure_reason' => $payout->failure_reason ?? 'Sending; Stripe has not answered yet.']),
        };
    }

    /**
     * A payout row was sent (by a merchant adjustment, Retry, or the
     * transfer.created webhook): its adjustment action is done. The ledger
     * line is the payout's own (CreditLedger::recordStripeTransfer).
     */
    public function payoutSent(MerchantPayout $payout, Carbon $at): void
    {
        if ($payout->status !== MerchantPayout::STATUS_SENT) {
            return;
        }

        $actions = CorrectionAction::where('kind', CorrectionAction::KIND_ADJUSTMENT_TRANSFER)->where('merchant_payout_id', $payout->id)
            ->where('status', '!=', CorrectionAction::STATUS_SUCCEEDED)->get();

        foreach ($actions as $action) {
            $action->update(['status' => CorrectionAction::STATUS_SUCCEEDED, 'stripe_id' => $payout->stripe_transfer_id, 'completed_at' => $at, 'failure_reason' => null]);
            self::syncStatus($action->correction);
        }
    }

    /** A refusal (definite: Stripe made nothing, next try gets a new key) or a lost answer (same key next time). */
    private function fail(CorrectionAction $action, string $message, bool $definite): void
    {
        $action->refresh();
        $action->update([
            'status' => $definite ? CorrectionAction::STATUS_FAILED : CorrectionAction::STATUS_PROCESSING,
            'attempts' => $action->attempts + ($definite ? 1 : 0),
            'failure_reason' => $message,
        ]);
    }

    /** The correction's status follows its actions: failed if any failed, otherwise processing until all succeed. */
    public static function syncStatus(Correction $correction): void
    {
        $correction->refresh();
        if (! in_array($correction->status, [Correction::STATUS_APPROVED, Correction::STATUS_PROCESSING, Correction::STATUS_FAILED], true)) {
            return;
        }

        $failed = $correction->actions()->where('status', CorrectionAction::STATUS_FAILED)->first();

        if ($failed !== null) {
            $correction->update(['status' => Correction::STATUS_FAILED, 'failure_reason' => $failed->failure_reason]);
        } elseif (! CorrectionDecisions::completeIfDone($correction)) {
            $correction->update(['status' => Correction::STATUS_PROCESSING,
                'failure_reason' => $correction->actions()->whereNotNull('failure_reason')->value('failure_reason')]);
        }
    }

    /**
     * Stripe's webhook confirmed a reversal for one of our actions (by its
     * key, or by the trr_ id we stored). Writes the refund's ledger lines
     * once: only an action not yet succeeded flips, and only when Stripe's
     * amount matches. Inside the webhook's transaction.
     */
    public function confirmReversal(array $reversal, Carbon $at): ?string
    {
        $action = CorrectionAction::where('kind', CorrectionAction::KIND_TRANSFER_REVERSAL)
            ->where(fn ($q) => $q->where('stripe_id', $reversal['id'] ?? '-')->orWhere('idempotency_key', $reversal['metadata']['lg_correction_key'] ?? '-'))
            ->first();

        if ($action === null) {
            return null;
        }

        if ((int) ($reversal['amount'] ?? 0) !== $action->amount_cents) {
            Log::warning('Stripe reversal amount does not match the correction', ['action' => $action->id, 'reversal' => $reversal['id'] ?? null]);

            return 'refused: reversal '.($reversal['id'] ?? '?').' is '.Money::format((int) ($reversal['amount'] ?? 0)).', the correction is '.Money::format($action->amount_cents);
        }

        $changed = CorrectionAction::whereKey($action->id)->where('status', '!=', CorrectionAction::STATUS_SUCCEEDED)
            ->update(['status' => CorrectionAction::STATUS_SUCCEEDED, 'stripe_id' => $reversal['id'], 'completed_at' => $at, 'failure_reason' => null]);

        if ($changed === 0) {
            return 'refund already recorded: '.$reversal['id'];
        }

        $correction = $action->correction;
        $this->ledger->refundRedemption($correction, $action->amount_cents, $action->payout, $reversal['id'], $at);
        self::syncStatus($correction);

        return 'refund recorded: '.$reversal['id'];
    }

    /**
     * Stripe's webhook about a card refund for one of our actions
     * (refund.created, refund.updated, refund.failed). In test mode Stripe
     * creates card refunds already succeeded, even on a payment whose funds
     * are still pending (trial of 2026-10-24), so refund.created usually
     * completes it. A succeeded refund writes its ledger line once; a failed
     * or cancelled one fails the action (the credits stay held: retry or
     * close). Inside the webhook's transaction.
     */
    public function confirmRefund(array $refund, Carbon $at): ?string
    {
        $action = CorrectionAction::where('kind', CorrectionAction::KIND_CARD_REFUND)
            ->where(fn ($q) => $q->where('stripe_id', $refund['id'] ?? '-')->orWhere('idempotency_key', $refund['metadata']['lg_correction_key'] ?? '-'))
            ->first();

        if ($action === null) {
            return null;
        }

        $status = (string) ($refund['status'] ?? '');

        if (in_array($status, ['failed', 'canceled'], true)) {
            $changed = CorrectionAction::whereKey($action->id)->where('status', '!=', CorrectionAction::STATUS_FAILED)->update([
                'status' => CorrectionAction::STATUS_FAILED, 'stripe_id' => $refund['id'], 'attempts' => $action->attempts + 1,
                'failure_reason' => 'Stripe says the card refund '.$status.($refund['failure_reason'] ?? null ? ' ('.str_replace('_', ' ', $refund['failure_reason']).')' : '').'. The credits stay held; retry or close it.',
            ]);
            self::syncStatus($action->correction);

            return $changed ? 'card refund '.$status.': '.$refund['id'] : 'card refund failure already recorded: '.$refund['id'];
        }

        if ($status !== 'succeeded') {
            $action->update(['stripe_id' => $refund['id'], 'failure_reason' => 'Stripe shows the card refund as '.str_replace('_', ' ', $status).'; waiting for it to finish.']);

            return 'card refund '.$status.': '.$refund['id'];
        }

        if ((int) ($refund['amount'] ?? 0) !== $action->amount_cents) {
            Log::warning('Stripe refund amount does not match the correction', ['action' => $action->id, 'refund' => $refund['id'] ?? null]);

            return 'refused: refund '.$refund['id'].' is '.Money::format((int) ($refund['amount'] ?? 0)).', the correction is '.Money::format($action->amount_cents);
        }

        $changed = CorrectionAction::whereKey($action->id)->where('status', '!=', CorrectionAction::STATUS_SUCCEEDED)
            ->update(['status' => CorrectionAction::STATUS_SUCCEEDED, 'stripe_id' => $refund['id'], 'completed_at' => $at, 'failure_reason' => null]);

        if ($changed === 0) {
            return 'card refund already recorded: '.$refund['id'];
        }

        $this->ledger->completeEmployerRefund($action->correction, $action->creditPurchase, $action->amount_cents, $refund['id'], $at);
        self::syncStatus($action->correction);

        return 'card refund recorded: '.$refund['id'];
    }
}
