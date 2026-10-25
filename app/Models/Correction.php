<?php

namespace App\Models;

use App\Support\CorrectionReasons;
use App\Support\Money;
use App\Support\TaxBreakdown;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request to fix a money mistake, and the platform admin's decision on it
 * (ADR 0028). Three types:
 * - redemption_refund: credits back to the employee for a cleared
 *   redemption (all or part of it), with the merchant's Stripe transfer
 *   reversed for that amount, or netted from what it is owed if simulated;
 * - merchant_adjustment: a merchant was owed a Stripe transfer the ledger
 *   shows but didn't get it; the existing payout path sends it;
 * - employer_refund: unspent credits back to the employer's card, as
 *   Stripe refunds on the original purchase payments.
 *
 * Statuses: requested → approved → processing → completed or failed, or
 * rejected; a failed one is retried or closed. open_key is set only while
 * the request is open, and is unique, so there is at most one open request
 * per item. Never sent to Redemption Fraud Detection, and never changes a
 * fraud decision.
 */
class Correction extends Model
{
    public const TYPE_REDEMPTION_REFUND = 'redemption_refund';

    public const TYPE_MERCHANT_ADJUSTMENT = 'merchant_adjustment';

    public const TYPE_EMPLOYER_REFUND = 'employer_refund';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    /** A Stripe action was refused. Retried safely, or closed. */
    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    /** A failed correction the admin stopped: nothing (more) is refunded and any hold is released. */
    public const STATUS_CLOSED = 'closed';

    /** Statuses that keep open_key set: at most one of these per item. */
    public const OPEN_STATUSES = [self::STATUS_REQUESTED, self::STATUS_APPROVED, self::STATUS_PROCESSING, self::STATUS_FAILED];

    /** Approved amounts that count against what is left to refund (everything but rejected, closed and not yet decided). */
    public const COUNTED_STATUSES = [self::STATUS_APPROVED, self::STATUS_PROCESSING, self::STATUS_COMPLETED, self::STATUS_FAILED];

    protected $fillable = [
        'type', 'status', 'open_key', 'requested_by_user_id', 'requested_by_role', 'redemption_id', 'merchant_payout_id',
        'employer_id', 'merchant_id', 'employee_id', 'reason_category', 'note', 'amount_cents', 'subtotal_cents', 'tax_cents',
        'tax_lines', 'decided_by_user_id', 'decided_at', 'decision_reason', 'failure_reason', 'completed_at', 'fee_cents',
    ];

    protected function casts(): array
    {
        return ['tax_lines' => 'array', 'decided_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** "redemption:42", "payout:7" or "employer:1": the item a request is about. */
    public static function openKeyFor(string $type, int $id): string
    {
        return match ($type) {
            self::TYPE_REDEMPTION_REFUND => 'redemption:'.$id,
            self::TYPE_MERCHANT_ADJUSTMENT => 'payout:'.$id,
            self::TYPE_EMPLOYER_REFUND => 'employer:'.$id,
        };
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(Redemption::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(MerchantPayout::class, 'merchant_payout_id');
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CorrectionItem::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(CorrectionAction::class)->orderBy('id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_REDEMPTION_REFUND => 'Redemption refund',
            self::TYPE_MERCHANT_ADJUSTMENT => 'Merchant adjustment',
            self::TYPE_EMPLOYER_REFUND => 'Refund of unspent credits',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_REQUESTED => 'Requested',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_REJECTED => 'Not approved',
            self::STATUS_CLOSED => 'Closed without a refund',
        };
    }

    public function reasonLabel(): string
    {
        return CorrectionReasons::label(CorrectionReasons::contextOf($this), $this->reason_category);
    }

    /** The tax part of a refund of menu items, as snapshotted when it was approved; null for a typed-in amount. */
    public function taxBreakdown(): ?TaxBreakdown
    {
        return $this->tax_cents === null || $this->amount_cents === null ? null
            : new TaxBreakdown(TaxBreakdown::ADDED, $this->amount_cents, $this->subtotal_cents, $this->tax_cents, $this->tax_lines ?? []);
    }

    /** "$2.30", or "—" before an amount is approved. */
    public function amountLabel(): string
    {
        return $this->amount_cents === null ? '—' : Money::format($this->amount_cents);
    }
}
