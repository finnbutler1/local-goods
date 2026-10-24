<?php

namespace App\Services\Corrections;

use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Services\CreditLedger;
use App\Services\Payments\StripeGateway;
use App\Services\Payments\StripeGatewayException;
use App\Support\Money;
use App\Support\StripeEnvironment;
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
    public function __construct(private StripeGateway $stripe, private CreditLedger $ledger) {}

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
        foreach ($correction->actions()->whereIn('status', [CorrectionAction::STATUS_PENDING, CorrectionAction::STATUS_PROCESSING, CorrectionAction::STATUS_FAILED])->get() as $action) {
            if ($action->kind === CorrectionAction::KIND_SIMULATED) {
                continue;
            }

            try {
                $this->send($action);
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
            };
        } catch (StripeGatewayException $e) {
            $this->fail($action, $e->getMessage(), $e->definite);

            return;
        }

        $action->update(['stripe_id' => $made['id'], 'failure_reason' => null]);
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
}
