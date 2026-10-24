<?php

namespace App\Models;

use App\Support\Money;
use App\Support\TaxBreakdown;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time pay code for paying in person (ADR 0026): tied to one employee,
 * one merchant and one amount, for EXPIRES_MINUTES. No credits move until the
 * merchant confirms it; then it is "used" and points at the redemption.
 * Found by code_hash (an HMAC); the code itself is encrypted at rest and
 * never logged. App\Services\PayCodes is the only place that changes them.
 */
class PayCode extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_USED = 'used';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const BY_EMPLOYEE = 'employee';

    public const BY_MERCHANT = 'merchant';

    /** The employee asked for a new code, which cancels the old one. */
    public const BY_REPLACED = 'replaced';

    /** The balance check at confirm failed. */
    public const BY_BALANCE = 'balance';

    public const EXPIRES_MINUTES = 10;

    protected $fillable = [
        'employee_id', 'merchant_id', 'amount_cents', 'items', 'code_hash', 'code', 'status',
        'cancelled_by', 'expires_at', 'used_at', 'cancelled_at', 'redemption_id',
        'tax_basis', 'subtotal_cents', 'tax_cents', 'tax_lines',
    ];

    protected $hidden = ['code', 'code_hash'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'tax_lines' => 'array',
            'code' => 'encrypted',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(Redemption::class);
    }

    /** Active and not past its expiry time. */
    public function isLive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->expires_at->isFuture();
    }

    /** The stored status, or "expired" for an active code past its time (saved on the next PayCodes read). */
    public function currentStatus(): string
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->expires_at->isFuture()
            ? self::STATUS_EXPIRED
            : $this->status;
    }

    /** "K7M 4PQ": easier to read out at a counter. */
    public function displayCode(): string
    {
        return substr($this->code, 0, 3).' '.substr($this->code, 3);
    }

    /** "K, 7, M, 4, P, Q": what a screen reader says, one character at a time. */
    public function spokenCode(): string
    {
        return implode(', ', str_split($this->code));
    }

    /** "2× Latte, Other amount $3.00", or null for a bare amount. Same wording as Redemption::itemsSummary. */
    public function itemsSummary(): ?string
    {
        if (empty($this->items)) {
            return null;
        }

        return collect($this->items)->map(fn (array $item) => $item['merchant_product_id'] === null && $item['name'] === RedemptionItem::OTHER_AMOUNT
            ? RedemptionItem::OTHER_AMOUNT.' '.Money::format($item['unit_price_cents'])
            : "{$item['quantity']}× {$item['name']}")->implode(', ');
    }

    /** The DC sales tax fixed when the code was made (ADR 0027); confirming redeems with exactly this. */
    public function taxBreakdown(): ?TaxBreakdown
    {
        return TaxBreakdown::fromStored($this->only(['tax_basis', 'amount_cents', 'subtotal_cents', 'tax_cents', 'tax_lines']), $this->items ?? []);
    }
}
