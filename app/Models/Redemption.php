<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Redemption extends Model
{
    public const STATUS_APPROVED = 'approved';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'employee_id', 'merchant_id', 'amount_cents', 'status', 'risk_score',
        'ai_decision', 'ai_explanation', 'signals', 'scored_by', 'created_at',
        'reviewed_by', 'reviewed_by_user_id', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['signals' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isUnderReview(): bool
    {
        return $this->status === self::STATUS_UNDER_REVIEW;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /** What the AI decided, e.g. "hold (risk 82)". Kept apart from the person's call. */
    public function aiDecisionLabel(): string
    {
        if ($this->scored_by === 'fallback') {
            return 'automatic hold (the AI check did not complete)';
        }

        return $this->ai_decision.($this->risk_score !== null ? " (risk {$this->risk_score})" : '');
    }

    /** Who made the final call, e.g. "approved by Sam Taylor on Oct 12, 3:04 PM". */
    public function finalDecisionLabel(): string
    {
        if ($this->isUnderReview()) {
            return 'waiting for a BID admin';
        }

        if ($this->reviewed_by) {
            return "{$this->status} by {$this->reviewed_by} on "
                .$this->reviewed_at->setTimezone('America/New_York')->format('M j, g:i A');
        }

        return 'approved automatically: the AI cleared it';
    }
}
