<?php

namespace App\Services\Payments;

use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Services\CreditLedger;
use App\Support\StripeMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends Stripe transfers for a merchant's Stripe-channel payout rows (made
 * by StripeFunding when a redemption clears). Separate charges and
 * transfers: each Transfer carries the funding purchase's transfer_group
 * and its charge as source_transaction. Only ever looks at Stripe-channel
 * rows, so seeded or simulated money owed can't reach Stripe.
 *
 * Called after the clearing transaction has committed, never inside it: a
 * Stripe outage must not block or undo a redemption or a review. Nothing
 * here throws; failures are written on the row (still owed) and retried
 * later (next clear, onboarding finished, or Retry on reconciliation).
 */
class MerchantPayouts
{
    public function __construct(private StripeGateway $stripe, private CreditLedger $ledger, private TransferGate $gate) {}

    /**
     * send(), but never throws: for callers that just cleared a redemption.
     * Anything unexpected is logged (no keys, no names) and left owed.
     */
    public function sendSafely(Merchant $merchant): void
    {
        try {
            $this->send($merchant);
        } catch (Throwable $e) {
            Log::error('Stripe transfer step failed; amounts stay owed', ['merchant' => $merchant->id, 'type' => class_basename($e)]);
        }
    }

    /** @return array{sent: int, failed: int, waiting: int} */
    public function send(Merchant $merchant): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'waiting' => 0];

        if (! StripeMode::active()) {
            return $result;
        }

        $merchant->refresh();
        $rows = $merchant->payouts()->whereIn('status', MerchantPayout::SENDABLE_STATUSES)->orderBy('id')->get();

        if (! $merchant->isStripeOnboarded()) {
            $result['waiting'] = $rows->count();

            return $result;
        }

        foreach ($rows as $row) {
            $outcome = $this->sendOne($merchant, $row);
            $result[$outcome]++;
        }

        return $result;
    }

    /** @return 'sent'|'failed'|'waiting' */
    private function sendOne(Merchant $merchant, MerchantPayout $row): string
    {
        if ($row->redemption === null || ! $this->gate->allows($row->redemption)) {
            $row->update(['status' => MerchantPayout::STATUS_FAILED, 'failure_reason' => 'The transfer gate no longer allows this redemption.']);

            return 'failed';
        }

        // Claim the row for this attempt. A row left pending by a call whose
        // outcome we never learned keeps its key, so Stripe won't pay twice.
        $key = DB::transaction(function () use ($row) {
            $fresh = MerchantPayout::whereKey($row->id)->lockForUpdate()->first();

            if (! in_array($fresh->status, MerchantPayout::SENDABLE_STATUSES, true)) {
                return null;
            }

            $key = $fresh->status === MerchantPayout::STATUS_PENDING && $fresh->idempotency_key !== null
                ? $fresh->idempotency_key
                : 'lg-transfer-'.Str::uuid();

            $fresh->update(['status' => MerchantPayout::STATUS_PENDING, 'idempotency_key' => $key]);

            return $key;
        });

        if ($key === null) {
            return 'waiting';
        }

        try {
            $transfer = $this->stripe->createTransfer(array_filter([
                'amount' => $row->amount_cents,
                'currency' => config('services.stripe.currency'),
                'destination' => $merchant->stripe_account_id,
                'transfer_group' => $row->transfer_group,
                'source_transaction' => $row->source_charge_id,
                'description' => 'Local Goods: cleared redemption #'.$row->redemption_id.' (test mode)',
                'metadata' => ['lg_payout_key' => $key, 'lg_redemption_id' => (string) $row->redemption_id],
            ], fn ($v) => $v !== null), $key);
        } catch (StripeGatewayException $e) {
            $row->refresh()->update([
                // A timeout stays pending (same key next time); a refusal is failed (new key next time).
                'status' => $e->definite ? MerchantPayout::STATUS_FAILED : MerchantPayout::STATUS_PENDING,
                'failure_reason' => $e->getMessage(),
            ]);

            return 'failed';
        }

        $this->ledger->recordStripeTransfer($row->refresh(), $transfer['id']);

        return 'sent';
    }
}
