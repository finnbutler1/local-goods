<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One menu item line a redemption refund covers (ADR 0028): how many of it,
 * and their price before tax. Written once, when the refund is approved.
 */
class CorrectionItem extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['correction_id', 'redemption_item_id', 'quantity', 'amount_cents'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Correction items are written once.'));
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(Correction::class);
    }

    public function redemptionItem(): BelongsTo
    {
        return $this->belongsTo(RedemptionItem::class);
    }
}
