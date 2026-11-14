<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to pay a district's BID annual program fee for one program
 * year (ADR 0044). The tier, member count and amount are copied from the
 * district when the row is made, so a later change to the district never
 * changes a payment. A row only ever goes pending → paid; while it is
 * pending, fee_key holds the program year, and the same row is reused for
 * every new Checkout (a fresh payment_token each time), so an abandoned or
 * expired attempt never blocks the year. Only BidProgramFees changes it.
 */
class BidProgramFeePayment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'district_id', 'program_year', 'period_starts_on', 'period_ends_on', 'tier', 'member_businesses',
        'amount_cents', 'currency', 'status', 'fee_key', 'payment_token', 'requested_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'paid_at' => 'datetime',
            'admin_noted_at' => 'datetime',
            'program_year' => 'integer',
            'amount_cents' => 'integer',
            'member_businesses' => 'integer',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function noteBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_note_by_user_id');
    }
}
