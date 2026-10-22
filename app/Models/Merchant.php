<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    protected $fillable = [
        'bid_id', 'name', 'category', 'address', 'user_id', 'stripe_account_id', 'stripe_onboarding_key',
        'stripe_details_submitted', 'stripe_payouts_enabled', 'stripe_transfers_active', 'stripe_synced_at',
        'stripe_set_up_by_platform',
    ];

    protected function casts(): array
    {
        return [
            'stripe_details_submitted' => 'boolean',
            'stripe_payouts_enabled' => 'boolean',
            'stripe_transfers_active' => 'boolean',
            'stripe_synced_at' => 'datetime',
            'stripe_set_up_by_platform' => 'boolean',
        ];
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    /** The login that runs this business (the merchant role), if any. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(MerchantPayout::class);
    }

    /**
     * Cleared redemptions not yet paid out, computed from the ledger. Held
     * redemptions are not in it: no money is owed until a redemption clears.
     */
    public function payableCents(): int
    {
        return (int) $this->ledgerEntries()->sum('amount_cents');
    }

    /**
     * The part of payableCents() that will be paid by a Stripe transfer:
     * Stripe-funded cleared redemptions not yet transferred (ADR 0015).
     */
    public function owedViaStripeCents(): int
    {
        return (int) $this->payouts()->whereIn('status', MerchantPayout::UNPAID_STRIPE_STATUSES)->sum('amount_cents');
    }

    /** The rest of what is owed: seeded or simulated history, never sent to Stripe. */
    public function owedSimulatedCents(): int
    {
        return $this->payableCents() - $this->owedViaStripeCents();
    }

    /** Finished Stripe onboarding: Stripe says it can receive transfers. */
    public function isStripeOnboarded(): bool
    {
        return $this->stripe_account_id !== null && $this->stripe_transfers_active === true;
    }

    /** not_started | in_progress | complete, for badges. */
    public function stripeOnboardingStatus(): string
    {
        return match (true) {
            $this->stripe_account_id === null => 'not_started',
            $this->isStripeOnboarded() => 'complete',
            default => 'in_progress',
        };
    }

    /** Shown wherever a merchant's Stripe status is, so a platform-made demo account is never passed off as self-onboarded. */
    public const PLATFORM_SETUP_LABEL = 'Demo account set up by Local Goods in test mode';

    public function stripeOnboardingLabel(): string
    {
        if ($this->stripe_set_up_by_platform && $this->stripe_account_id !== null) {
            return $this->isStripeOnboarded() ? self::PLATFORM_SETUP_LABEL : self::PLATFORM_SETUP_LABEL.' (Stripe is still checking it)';
        }

        return match ($this->stripeOnboardingStatus()) {
            'not_started' => 'Not connected to Stripe',
            'complete' => 'Stripe onboarding complete',
            default => $this->stripe_details_submitted ? 'Stripe is checking the details' : 'Stripe onboarding started, not finished',
        };
    }
}
