<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One BID decision on a real merchant candidate, in an append-only log.
 * Approving only records the decision: it never creates a demo merchant or
 * puts the business into any transaction. A change of mind is a new row.
 */
class MerchantReviewDecision extends Model
{
    public const DECISIONS = [
        'approve' => 'Approved',
        'reject' => 'Rejected',
        'needs_research' => 'Needs research',
    ];

    public const UPDATED_AT = null;

    protected $fillable = ['candidate_id', 'decision', 'note', 'user_id', 'ai_classification', 'ai_prompt_version'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Merchant review decisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Merchant review decisions are append-only.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::DECISIONS[$this->decision] ?? $this->decision;
    }
}
