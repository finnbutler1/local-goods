<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One change to a BID partnership (ADR 0031): who, when, why, the terms
 * before and after. Append-only, like the fee audit log. Only
 * PartnershipSettings writes one.
 */
class PartnershipChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['partnership_id', 'changed_by_user_id', 'reason', 'before', 'after'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Partnership changes are an audit log and never edited.'));
        static::deleting(fn () => throw new LogicException('Partnership changes are an audit log and never deleted.'));
    }

    public function partnership(): BelongsTo
    {
        return $this->belongsTo(Partnership::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /** @return list<string> the terms this change altered */
    public function changedTerms(): array
    {
        return array_values(array_filter(Partnership::TERMS, fn ($term) => ($this->before[$term] ?? null) !== ($this->after[$term] ?? null)));
    }
}
