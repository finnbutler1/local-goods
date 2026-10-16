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

    /** Statuses that are in the ledger. */
    public const PAID_STATUSES = [self::STATUS_SIMULATED, self::STATUS_PAID];

    protected $fillable = [
        'employer_id', 'amount_cents', 'reference', 'purchased_at', 'status', 'payment_token',
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

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class);
    }

    /** Stripe-funded payouts this purchase's charge pays for. */
    public function fundedPayouts(): HasMany
    {
        return $this->hasMany(MerchantPayout::class);
    }

    /** Money from this Stripe payment not yet claimed by a merchant transfer. */
    public function unclaimedCents(): int
    {
        return $this->amount_cents - (int) $this->fundedPayouts()->whereNull('reversed_at')->sum('amount_cents');
    }
}
