<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One step of a merchant application (ADR 0031): from, to, who, when and
 * why. Append-only; only MerchantApplications writes one.
 */
class MerchantApplicationEvent extends Model
{
    public const UPDATED_AT = null;

    public const ACTOR_APPLICANT = 'applicant';

    public const ACTOR_STAFF = 'staff';

    public const ACTOR_STRIPE = 'stripe';

    public const ACTOR_SEED = 'seed';

    protected $fillable = ['merchant_application_id', 'from_status', 'to_status', 'actor', 'actor_user_id', 'reason', 'created_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Application steps are an audit log and never edited.'));
        static::deleting(fn () => throw new LogicException('Application steps are an audit log and never deleted.'));
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(MerchantApplication::class, 'merchant_application_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** Who, as the applicant sees it: staff by initials only. */
    public function actorLabel(): string
    {
        return match ($this->actor) {
            self::ACTOR_APPLICANT => 'You (the applicant)',
            self::ACTOR_STRIPE => 'Stripe',
            self::ACTOR_SEED => 'Demo seed',
            default => $this->actorUser ? User::initialsOf($this->actorUser->name).' ('.$this->actorUser->role->label().')' : 'Local Goods staff',
        };
    }
}
