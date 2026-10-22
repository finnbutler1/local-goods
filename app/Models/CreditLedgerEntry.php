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
 * the employer (credits bought but not yet handed out), a merchant
 * (cleared redemptions not yet paid out) or the Local Goods platform (fees
 * it earned, ADR 0021). A balance is always the sum of an account's
 * entries. See docs/adr/0012.
 */
class CreditLedgerEntry extends Model
{
    public const TYPE_ALLOWANCE = 'allowance';

    public const TYPE_REDEMPTION = 'redemption';

    /** A person approved a held redemption: the reserved credits are spent (amount 0, already debited). */
    public const TYPE_REVIEW_APPROVED = 'review_approved';

    /** A person denied a held redemption as "no purchase happened": the reserved credits go back to the employee. */
    public const TYPE_REVIEW_REJECTED = 'review_rejected';

    /** Employer account: a person denied a held redemption as suspected abuse, so its credits go back to the employer's pool (ADR 0016). */
    public const TYPE_REVIEW_DENIED_TO_EMPLOYER = 'review_denied_to_employer';

    /** Employer account: the employer bought credits. */
    public const TYPE_PURCHASE = 'purchase';

    /** Employer account: credits handed to an employee (the employee side is an allowance entry). */
    public const TYPE_ALLOCATION_OUT = 'allocation_out';

    /** Merchant account: a redemption was cleared, so the merchant is owed it. Never written while held. */
    public const TYPE_MERCHANT_EARNED = 'merchant_earned';

    /** Merchant account: money paid out to the merchant. */
    public const TYPE_PAYOUT = 'payout';

    /** Merchant account: a Stripe transfer was reversed, so the merchant is owed that amount again. */
    public const TYPE_PAYOUT_REVERSED = 'payout_reversed';

    /** Platform account: the platform fee paid on top of a purchase's credits (ADR 0021). Never on the employer's balance. */
    public const TYPE_PLATFORM_FEE = 'platform_fee';

    /** "platform" is true on platform-account entries and null on every other one. */
    public const ACCOUNT_COLUMNS = ['employee_id', 'employer_id', 'merchant_id', 'platform'];

    public const UPDATED_AT = null;

    protected $fillable = [
        'employee_id', 'employer_id', 'merchant_id', 'amount_cents', 'type', 'redemption_id',
        'credit_purchase_id', 'credit_allocation_id', 'merchant_payout_id', 'description', 'decided_by', 'created_at', 'platform',
    ];

    protected function casts(): array
    {
        return ['platform' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            $accounts = collect(self::ACCOUNT_COLUMNS)->filter(fn ($column) => $entry->{$column} !== null)->count();

            if ($accounts !== 1) {
                throw new LogicException('A ledger entry must belong to exactly one account (employee, employer, merchant or platform).');
            }

            if ($entry->platform === false) {
                throw new LogicException('The platform marker is true or empty, never false.');
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
