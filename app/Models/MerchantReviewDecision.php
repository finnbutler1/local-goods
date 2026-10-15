<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One BID decision on a real merchant candidate, in an append-only log.
 * Approving only records the decision: it never creates a demo merchant or
 * puts the business into any transaction. A change of mind is a new row.
 *
 * Policy: the AI only recommends. Every decision is made by a person, a BID
 * or platform admin, so a row without one is refused here, whatever code
 * tries to write it. The AI's answer is stored only as what the person saw.
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
        static::creating(function (self $decision) {
            $person = User::find($decision->user_id);

            if ($person === null || ! $person->hasRole(Role::BidAdmin, Role::PlatformAdmin)) {
                throw new LogicException('A merchant decision must be made by a person: a BID admin or platform admin.');
            }
        });
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
