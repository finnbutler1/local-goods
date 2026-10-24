<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a correction does with money (ADR 0028): a Stripe transfer
 * reversal, a Stripe refund on one purchase payment, sending a merchant's
 * owed transfer through the existing payout path, or a simulated step with
 * no Stripe object. Each Stripe action has its own idempotency key, kept
 * while its outcome is unknown, so a retry can never act twice.
 */
class CorrectionAction extends Model
{
    public const KIND_TRANSFER_REVERSAL = 'transfer_reversal';

    public const KIND_CARD_REFUND = 'card_refund';

    /** Sending a merchant's owed Stripe payout row (MerchantPayouts::send); no Stripe call of its own. */
    public const KIND_ADJUSTMENT_TRANSFER = 'adjustment_transfer';

    public const KIND_SIMULATED = 'simulated';

    /** Not sent to Stripe yet. */
    public const STATUS_PENDING = 'pending';

    /** Sent to Stripe (or its answer was lost); waiting for the webhook. */
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'correction_id', 'kind', 'credit_purchase_id', 'merchant_payout_id', 'amount_cents', 'status',
        'idempotency_key', 'stripe_id', 'attempts', 'failure_reason', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(Correction::class);
    }

    public function creditPurchase(): BelongsTo
    {
        return $this->belongsTo(CreditPurchase::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(MerchantPayout::class, 'merchant_payout_id');
    }

    public function isStripe(): bool
    {
        return $this->kind !== self::KIND_SIMULATED;
    }
}
