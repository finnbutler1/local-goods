<?php

namespace App\Models;

use App\Services\Fraud\FraudVerdict;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Redemption extends Model
{
    public const STATUS_APPROVED = 'approved';

    public const STATUS_UNDER_REVIEW = 'under_review';

    /** Denied by a person after a hold (shown as "Denied"; the stored value predates the word). */
    public const STATUS_REJECTED = 'rejected';

    /** Ordered ahead and shown at the counter (the wallet's "Order to go" tab). */
    public const CHANNEL_TO_GO = 'to_go';

    /** Paid at the counter with a pay code the merchant confirmed (ADR 0026). */
    public const CHANNEL_IN_PERSON = 'in_person';

    protected $fillable = [
        'employee_id', 'merchant_id', 'amount_cents', 'status', 'risk_score',
        'ai_decision', 'ai_explanation', 'signals', 'scored_by', 'created_at',
        'reviewed_by', 'reviewed_by_user_id', 'reviewed_at', 'channel',
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

    /** What it was for, if the employee picked from the menu (ADR 0023). Display only. */
    public function items(): HasMany
    {
        return $this->hasMany(RedemptionItem::class)->orderBy('id');
    }

    /** "2× Latte, 1× Croissant", or null for a bare amount. Load items first. */
    public function itemsSummary(): ?string
    {
        return $this->items->isEmpty() ? null : $this->items->map->label()->implode(', ');
    }

    /** A person's Approve or Deny, if there was one (ADR 0016). */
    public function decision(): HasOne
    {
        return $this->hasOne(RedemptionDecision::class);
    }

    /** Its Stripe-channel payout row, if it was Stripe-funded (ADR 0015). */
    public function stripePayout(): HasOne
    {
        return $this->hasOne(MerchantPayout::class);
    }

    /**
     * Redemption Fraud Detection cleared it on its own: a real AI answer of
     * "clear" and no person involved. Never true for an AI failure (always a
     * hold) or for seeded history.
     */
    public function wasClearedByAi(): bool
    {
        return $this->ai_decision === FraudVerdict::CLEAR
            && ! in_array($this->scored_by, ['fallback', 'seed'], true)
            && $this->reviewed_at === null;
    }

    public function isInPerson(): bool
    {
        return $this->channel === self::CHANNEL_IN_PERSON;
    }

    /**
     * "To go" or "In person". Display only (ADR 0026): the channel is never
     * sent to Redemption Fraud Detection.
     */
    public function channelLabel(): string
    {
        return $this->isInPerson() ? 'In person' : 'To go';
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
            return ($this->isRejected() ? 'denied' : $this->status)." by {$this->reviewed_by} on "
                .$this->reviewed_at->setTimezone('America/New_York')->format('M j, g:i A');
        }

        return 'approved automatically: the AI cleared it';
    }

    /**
     * The redemption's path, one step at a time, for the review page and the
     * merchant's payout history, e.g. ["AI held (risk 88)", "approved by BID
     * admin S.T. · Oct 17", "transferred tr_…"]. Staff by initials; the
     * employee is never named here. Load decision and stripePayout first.
     *
     * @return list<string>
     */
    public function auditTrail(): array
    {
        $steps = [match (true) {
            $this->scored_by === 'seed' => 'Seeded history (before AI scoring)',
            $this->scored_by === 'fallback' => 'AI check failed → automatic hold',
            $this->ai_decision === FraudVerdict::CLEAR => "AI cleared (risk {$this->risk_score})",
            default => 'AI held'.($this->risk_score !== null ? " (risk {$this->risk_score})" : ''),
        }];

        $decision = $this->decision;

        if ($decision !== null) {
            $when = $decision->decided_at->setTimezone('America/New_York')->format('M j');
            $steps[] = ($decision->isApproval() ? 'approved' : 'denied').' by '.$decision->deciderLabel().' · '.$when;
        } elseif ($this->isUnderReview()) {
            $steps[] = 'waiting for a BID admin';
        }

        if ($this->isRejected()) {
            $steps[] = 'no transfer · '.Money::format($this->amount_cents).' credit back to the '.($decision?->credit_returned_to ?? 'employee');

            return $steps;
        }

        if ($this->isUnderReview()) {
            $steps[] = 'no transfer while held';

            return $steps;
        }

        $payout = $this->stripePayout;
        $steps[] = match ($payout?->status) {
            null => 'owed to the merchant, simulated (no Stripe transfer)',
            MerchantPayout::STATUS_SENT => "transferred {$payout->stripe_transfer_id}",
            MerchantPayout::STATUS_OWED => 'owed via Stripe, waiting for the merchant\'s onboarding',
            MerchantPayout::STATUS_PENDING => 'Stripe transfer sending',
            MerchantPayout::STATUS_FAILED => 'Stripe transfer not sent yet, still owed',
            MerchantPayout::STATUS_REVERSED => "transfer {$payout->stripe_transfer_id} reversed, owed again",
            default => $payout->status,
        };

        return $steps;
    }
}
