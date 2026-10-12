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
}
