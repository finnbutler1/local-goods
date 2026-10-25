<?php

namespace App\Services\Payments;

use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Services\CreditLedger;
use App\Support\StripeEnvironment;
use App\Support\StripeMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /**
     * One key per redemption (ADR 0016): this server, the redemption, when its
     * payout row was made, and how many times Stripe definitely refused it.
     * The same redemption always asks Stripe with the same key, so a double
     * click or a retry after a timeout can't create a second transfer. The
     * server id and the row's time keep keys apart when local and production
     * share a Stripe sandbox, or after a Reset demo reuses redemption ids.
     */
    public static function idempotencyKey(MerchantPayout $row): string
    {
        return 'lg-transfer-'.StripeEnvironment::instance().'-r'.$row->redemption_id
            .'-'.$row->created_at->getTimestamp().'-'.$row->attempts;
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
                : self::idempotencyKey($fresh);

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
                'metadata' => ['lg_payout_key' => $key, 'lg_redemption_id' => (string) $row->redemption_id]
                    // The Local Goods merchant fee held back from this transfer (ADR 0029), only when there is one.
                    + ($row->fee_cents > 0 ? ['lg_merchant_fee_cents' => (string) $row->fee_cents] : [])
                    + StripeEnvironment::tag(),
            ], fn ($v) => $v !== null), $key);
        } catch (StripeGatewayException $e) {
            $row->refresh();
            $row->update([
                // A timeout stays pending (same key next time). A refusal is
                // failed: Stripe made no transfer, so the next attempt may use
                // a new key (attempts + 1).
                'status' => $e->definite ? MerchantPayout::STATUS_FAILED : MerchantPayout::STATUS_PENDING,
                'attempts' => $row->attempts + ($e->definite ? 1 : 0),
                'failure_reason' => $e->getMessage(),
            ]);

            return 'failed';
        }

        $this->ledger->recordStripeTransfer($row->refresh(), $transfer['id']);

        return 'sent';
    }
}
