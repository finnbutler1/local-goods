<?php

namespace App\Models;

use App\Services\Districts\DistrictRules;
use App\Services\Eligibility\MerchantCandidates;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    protected $fillable = [
        'bid_id', 'name', 'category', 'address', 'user_id', 'stripe_account_id', 'stripe_onboarding_key',
        'stripe_details_submitted', 'stripe_payouts_enabled', 'stripe_transfers_active', 'stripe_synced_at',
        'stripe_set_up_by_platform', 'district_id', 'latitude', 'longitude', 'district_approved_at',
        'district_approved_by_user_id', 'live_at',
    ];

    protected function casts(): array
    {
        return [
            'stripe_details_submitted' => 'boolean',
            'stripe_payouts_enabled' => 'boolean',
            'stripe_transfers_active' => 'boolean',
            'stripe_synced_at' => 'datetime',
            'stripe_set_up_by_platform' => 'boolean',
            'district_approved_at' => 'datetime',
            'live_at' => 'datetime',
        ];
    }

    /** An approved merchant's location must be inside its district, however it is saved (ADR 0031). */
    protected static function booted(): void
    {
        static::saving(fn (Merchant $merchant) => DistrictRules::guardSave($merchant));
    }

    /** Merchants where this employee's credits may be spent (DistrictRules::mayRedeem). */
    public function scopeRedeemableFor(Builder $query, Employee $employee): void
    {
        $districtId = DistrictRules::programDistrictId($employee);

        $query->when($districtId !== null, fn (Builder $q) => $q->where('district_id', $districtId)
            ->whereNotNull('district_approved_at')->whereNotNull('live_at'));
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    /** The district it is approved in (ADR 0031), if any. */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
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

    /** Its menu, in menu order (ADR 0023). Only the demo merchants have one. */
    public function products(): HasMany
    {
        return $this->hasMany(MerchantProduct::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Only the fictional demo merchants may have products: a name in
     * DatabaseSeeder::DEMO_MERCHANTS and never a real candidate's name.
     */
    public function mayHaveProducts(): bool
    {
        $name = mb_strtolower($this->name);

        return in_array($this->name, array_column(DatabaseSeeder::DEMO_MERCHANTS, 0), true)
            && ! in_array($name, array_map(fn ($c) => mb_strtolower($c['name']), MerchantCandidates::load()), true);
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

    /** Stripe requires a contact email on a v2 account that receives transfers. Fictional for the demo merchants. */
    public function stripeContactEmail(): string
    {
        return $this->user?->email ?? 'merchant-'.$this->id.'@example.com';
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
