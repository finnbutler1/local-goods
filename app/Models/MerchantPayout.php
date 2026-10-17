<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money paid, or owed through Stripe, to a merchant for cleared redemptions.
 * Two channels that never mix (ADR 0015):
 *
 * - Simulated: the seed's weekly payouts. Recorded in the ledger with no
 *   Stripe transfer behind them.
 * - Stripe test mode: one row per Stripe-funded cleared redemption, made
 *   when it clears (owed), then pending while the Transfer is sent, then
 *   sent (with its tr_ id and one ledger payout entry) or failed (still
 *   owed, retried later). Only these rows are ever sent to Stripe.
 */
class MerchantPayout extends Model
{
    /** Stripe channel: cleared and funded, waiting for the merchant to finish onboarding. */
    public const STATUS_OWED = 'owed';

    /** Stripe channel: the Transfer is being sent (or we couldn't tell if Stripe got it). */
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    /** Stripe channel: Stripe refused the Transfer. Still owed; tried again later. */
    public const STATUS_FAILED = 'failed';

    public const STATUS_SIMULATED = 'simulated';

    /** Stripe channel: sent, then reversed in Stripe. Owed again; a person decides, never retried automatically. */
    public const STATUS_REVERSED = 'reversed';

    /** Rows that are in the ledger as a payout entry. */
    public const PAID_STATUSES = [self::STATUS_SENT, self::STATUS_SIMULATED];

    /** Stripe-channel rows not yet paid. */
    public const UNPAID_STRIPE_STATUSES = [self::STATUS_OWED, self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_REVERSED];

    /** Stripe-channel rows MerchantPayouts::send may (re)send. */
    public const SENDABLE_STATUSES = [self::STATUS_OWED, self::STATUS_PENDING, self::STATUS_FAILED];

    protected $fillable = [
        'merchant_id', 'amount_cents', 'status', 'stripe_transfer_id', 'paid_at', 'redemption_id',
        'credit_purchase_id', 'transfer_group', 'source_charge_id', 'idempotency_key', 'attempts', 'failure_reason', 'reversed_at',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function isStripe(): bool
    {
        return $this->status !== self::STATUS_SIMULATED;
    }

    /** @param  Builder<self>  $query */
    public function scopeStripe(Builder $query): void
    {
        $query->where('status', '!=', self::STATUS_SIMULATED);
    }

    /** @param  Builder<self>  $query */
    public function scopePaid(Builder $query): void
    {
        $query->whereIn('status', self::PAID_STATUSES);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(Redemption::class);
    }

    public function creditPurchase(): BelongsTo
    {
        return $this->belongsTo(CreditPurchase::class);
    }
}
