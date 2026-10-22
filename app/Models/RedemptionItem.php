<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a redemption was for (ADR 0023). The product's name and price are
 * copied at the time, so a later menu edit never changes history. A row
 * with no product is the "Other amount" part. Display only.
 */
class RedemptionItem extends Model
{
    public const OTHER_AMOUNT = 'Other amount';

    public const UPDATED_AT = null;

    protected $fillable = ['redemption_id', 'merchant_product_id', 'name', 'unit_price_cents', 'quantity'];

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(Redemption::class);
    }

    public function totalCents(): int
    {
        return $this->unit_price_cents * $this->quantity;
    }

    /** "2× Latte", or "Other amount $3.00". */
    public function label(): string
    {
        return $this->merchant_product_id === null && $this->name === self::OTHER_AMOUNT
            ? self::OTHER_AMOUNT.' '.Money::format($this->unit_price_cents)
            : "{$this->quantity}× {$this->name}";
    }
}
