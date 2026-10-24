<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The employer bought credits. A pending purchase is only a request: it has
 * no ledger entry, so its credits can't be handed out or spent. Once paid
 * the amount is a ledger entry on the employer's account: "simulated" (no
 * real money: the seed, or "Pay now" when Stripe keys are missing) or
 * "paid" (Stripe test mode, written only by a verified webhook). See
 * CreditLedger::payPurchase, markPurchasePaidByStripe and ADR 0015.
 */
class CreditPurchase extends Model
{
    public const STATUS_PENDING = 'pending';

    /** Paid with no real money: the seed, and "Pay now" in simulated mode. */
    public const STATUS_SIMULATED = 'simulated';

    /** Paid by card through Stripe Checkout in test mode, confirmed by webhook. */
    public const STATUS_PAID = 'paid';

    /**
     * HR cancelled the payment and Stripe confirmed no payment can still
     * arrive for it (its Checkout was expired or never opened). Never in the
     * ledger, never payable again.
     */
    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses that are in the ledger. */
    public const PAID_STATUSES = [self::STATUS_SIMULATED, self::STATUS_PAID];

    protected $fillable = [
        'employer_id', 'amount_cents', 'platform_fee_cents', 'reference', 'purchased_at', 'status', 'payment_token',
        'stripe_checkout_session_id', 'stripe_payment_intent_id', 'stripe_charge_id', 'paid_at', 'payment_error',
    ];

    protected function casts(): array
    {
        return ['purchased_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isStripe(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** What the employer pays: the credits plus the platform fee (0 unless the fee is on, ADR 0021). */
    public function totalCents(): int
    {
        return $this->amount_cents + (int) $this->platform_fee_cents;
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class);
    }

    /** Stripe-funded payouts this purchase's charge pays for. */
    public function fundedPayouts(): HasMany
    {
        return $this->hasMany(MerchantPayout::class);
    }

    /**
     * Money from this Stripe payment not yet claimed by a merchant transfer
     * or refunded to the employer (ADR 0028). Credits only: the platform fee
     * never funds a transfer. A reversed transfer keeps its claim: a person
     * decides what happens next. With no refunds, exactly as before.
     */
    public function unclaimedCents(): int
    {
        return $this->amount_cents - (int) $this->fundedPayouts()->sum('amount_cents') - $this->refundedCents();
    }

    /**
     * Credits from this purchase refunded to the employer, or held for a
     * refund on its way (ADR 0028). A refund that was closed without
     * happening no longer counts; one that went through always does.
     */
    public function refundedCents(): int
    {
        return (int) CorrectionAction::where('credit_purchase_id', $this->id)
            ->where(fn ($q) => $q->where('status', CorrectionAction::STATUS_SUCCEEDED)
                ->orWhereHas('correction', fn ($c) => $c->where('status', '!=', Correction::STATUS_CLOSED)))
            ->sum('amount_cents');
    }

    /**
     * What may still go back to the employer from this purchase: its credits
     * less what was refunded and, for a Stripe payment, less what merchant
     * transfers already claimed (that money has left Stripe's balance).
     */
    public function refundableCents(): int
    {
        return $this->isStripe() ? max(0, $this->unclaimedCents()) : max(0, $this->amount_cents - $this->refundedCents());
    }
}
