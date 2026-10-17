<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A person's Approve or Deny on a redemption Redemption Fraud Detection held
 * (ADR 0016). Append-only, at most one per redemption (a unique index), so
 * a double click or a second tab can't decide twice. Keeps who decided,
 * when, why, where denied credit went, and a copy of what the AI said.
 *
 * These are operational decisions, NOT eval labels: nothing in the fraud
 * eval (fraud_eval_cases.csv, holdout_3, /eval, adjudication) reads them.
 */
class RedemptionDecision extends Model
{
    public const APPROVE = 'approve';

    public const DENY = 'deny';

    public const UPDATED_AT = null;

    protected $fillable = [
        'redemption_id', 'decision', 'reason', 'credit_returned_to', 'decided_by_user_id', 'decided_by_name',
        'decided_by_role', 'ai_decision', 'ai_risk_score', 'ai_scored_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'backfilled' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $decision) {
            $person = User::find($decision->decided_by_user_id);

            if ($person === null || ! $person->hasRole(Role::BidAdmin, Role::PlatformAdmin)) {
                throw new LogicException('A redemption decision must be made by a person: a BID admin or platform admin.');
            }

            if (! in_array($decision->decision, [self::APPROVE, self::DENY], true) || trim((string) $decision->reason) === '') {
                throw new LogicException('A redemption decision needs approve or deny and a reason.');
            }
        });
        static::updating(fn () => throw new LogicException('Redemption decisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Redemption decisions are append-only.'));
    }

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(Redemption::class);
    }

    public function isApproval(): bool
    {
        return $this->decision === self::APPROVE;
    }

    /** "BID admin S.T.": the role and initials, for audit trails. Backfilled rows have no role. */
    public function deciderLabel(): string
    {
        $role = $this->decided_by_role ? Role::from($this->decided_by_role)->label().' ' : '';

        return $role.User::initialsOf($this->decided_by_name);
    }
}
