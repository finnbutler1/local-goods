<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One change to the merchant fee setting (ADR 0029): who, when, old → new
 * and why. Append-only: the newest row is the current fee, so the history
 * is never rewritten. Only MerchantFeeSettings::change writes one.
 */
class MerchantFeeChange extends Model
{
    protected $fillable = ['fee_bps', 'fixed_cents', 'previous_bps', 'previous_fixed_cents', 'reason', 'changed_by_user_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Fee changes are an audit log and never edited.'));
        static::deleting(fn () => throw new LogicException('Fee changes are an audit log and never deleted.'));
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
