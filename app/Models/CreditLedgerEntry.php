<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line in the immutable credit ledger. Corrections are made by adding
 * a new entry, never by editing or deleting an old one.
 *
 * Every entry belongs to exactly one account: an employee (their wallet),
 * the employer (credits bought but not yet handed out) or a merchant
 * (cleared redemptions not yet paid out). A balance is always the sum of an
 * account's entries. See docs/adr/0012.
 */
class CreditLedgerEntry extends Model
{
    public const TYPE_ALLOWANCE = 'allowance';
    public const TYPE_REDEMPTION = 'redemption';

    /** A person approved a held redemption: the reserved credits are spent (amount 0, already debited). */
    public const TYPE_REVIEW_APPROVED = 'review_approved';

    /** A person rejected a held redemption: the reserved credits go back to the employee. */
    public const TYPE_REVIEW_REJECTED = 'review_rejected';

    /** Employer account: the employer bought credits. */
    public const TYPE_PURCHASE = 'purchase';

    /** Employer account: credits handed to an employee (the employee side is an allowance entry). */
    public const TYPE_ALLOCATION_OUT = 'allocation_out';

    /** Merchant account: a redemption was cleared, so the merchant is owed it. Never written while held. */
    public const TYPE_MERCHANT_EARNED = 'merchant_earned';

    /** Merchant account: money paid out to the merchant. */
    public const TYPE_PAYOUT = 'payout';

    public const ACCOUNT_COLUMNS = ['employee_id', 'employer_id', 'merchant_id'];

    public const UPDATED_AT = null;

    protected $fillable = [
        'employee_id', 'employer_id', 'merchant_id', 'amount_cents', 'type', 'redemption_id',
        'credit_purchase_id', 'credit_allocation_id', 'merchant_payout_id', 'description', 'decided_by', 'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            $accounts = collect(self::ACCOUNT_COLUMNS)->filter(fn ($column) => $entry->{$column} !== null)->count();

            if ($accounts !== 1) {
                throw new LogicException('A ledger entry must belong to exactly one account (employee, employer or merchant).');
            }
        });
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are immutable.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(Redemption::class);
    }
}
