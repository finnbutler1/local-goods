<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One recorded move of earned fees from the customer funds account to Local
 * Goods Operating (ADR 0034): the amount, why, who decided and the amount
 * they reviewed. Append-only: once made, only the Stripe outcome (status,
 * transfer id, attempts, failure, time) is ever filled in, and a row is never
 * deleted. open_key is set only while the sweep is pending, so one is in
 * flight at a time.
 */
class OperatingSweep extends Model
{
    public const CHANNEL_STRIPE = 'stripe';

    public const CHANNEL_SIMULATED = 'simulated';

    /** Stripe channel: the transfer is being sent (or we couldn't tell if Stripe got it). */
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    /** Recorded in the ledger with no Stripe transfer behind it. */
    public const STATUS_SIMULATED = 'simulated';

    /** Stripe definitely refused; the reserved amount went back to unswept fees. */
    public const STATUS_FAILED = 'failed';

    /** Sweeps whose money left the customer funds account. */
    public const DONE_STATUSES = [self::STATUS_SENT, self::STATUS_SIMULATED];

    /** The only columns that change after a sweep is made: Stripe's outcome. */
    public const OUTCOME_COLUMNS = ['status', 'open_key', 'stripe_transfer_id', 'idempotency_key', 'attempts', 'failure_reason', 'swept_at', 'updated_at'];

    public const OPEN_KEY = 'open';

    protected $fillable = [
        'amount_cents', 'channel', 'status', 'open_key', 'reason', 'reviewed_against_cents', 'decided_by_user_id',
        'stripe_transfer_id', 'idempotency_key', 'attempts', 'failure_reason', 'swept_at',
    ];

    protected function casts(): array
    {
        return ['swept_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $sweep) {
            if (array_diff(array_keys($sweep->getDirty()), self::OUTCOME_COLUMNS) !== []) {
                throw new LogicException('A sweep is never edited; only its Stripe outcome is recorded.');
            }
        });
        static::deleting(fn () => throw new LogicException('Sweeps are a record and never deleted.'));
    }

    public function isStripe(): bool
    {
        return $this->channel === self::CHANNEL_STRIPE;
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }
}
