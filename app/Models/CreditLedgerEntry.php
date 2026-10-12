<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line in the immutable credit ledger. Corrections are made by adding
 * a new entry, never by editing or deleting an old one.
 */
class CreditLedgerEntry extends Model
{
    public const TYPE_ALLOWANCE = 'allowance';
    public const TYPE_REDEMPTION = 'redemption';

    /** A person approved a held redemption: the reserved credits are spent (amount 0, already debited). */
    public const TYPE_REVIEW_APPROVED = 'review_approved';

    /** A person rejected a held redemption: the reserved credits go back to the employee. */
    public const TYPE_REVIEW_REJECTED = 'review_rejected';

    public const UPDATED_AT = null;

    protected $fillable = ['employee_id', 'amount_cents', 'type', 'redemption_id', 'description', 'decided_by', 'created_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are immutable.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(Redemption::class);
    }
}
