<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shop's request to join Local Goods (ADR 0031). Only
 * MerchantApplications changes its status, one step at a time, and logs
 * each step in merchant_application_events. The AI's eligibility
 * suggestion is stored here; a BID admin decides.
 */
class MerchantApplication extends Model
{
    public const APPLIED = 'applied';

    public const ELIGIBILITY_CHECKED = 'eligibility_checked';

    public const BID_APPROVED = 'bid_approved';

    public const DECLINED = 'declined';

    public const STRIPE_ONBOARDING = 'stripe_onboarding';

    public const LIVE = 'live';

    /** The five steps shown to the applicant, in order. Declined ends the line at step 3. */
    public const STEPS = [
        self::APPLIED => 'Applied',
        self::ELIGIBILITY_CHECKED => 'Eligibility check',
        self::BID_APPROVED => 'BID approval',
        self::STRIPE_ONBOARDING => 'Stripe payouts set up',
        self::LIVE => 'Live',
    ];

    /** The categories Merchant Eligibility Matching knows (its prompt's grouping). */
    public const CATEGORIES = ['cafe' => 'Cafe', 'restaurant' => 'Restaurant', 'retail' => 'Retail', 'salon' => 'Salon', 'fitness' => 'Fitness'];

    public const SAMPLE_LABEL = 'Fictional sample application';

    public const APPLICANT_LABEL = 'Applicant — not a Local Goods participant yet.';

    protected $fillable = [
        'business_name', 'address', 'category', 'contact_email', 'independence_answer', 'status', 'status_token_hash',
        'prototype_acknowledged_at', 'district_id', 'is_sample',
    ];

    protected $hidden = ['status_token_hash'];

    protected function casts(): array
    {
        return [
            'prototype_acknowledged_at' => 'datetime',
            'inside_district' => 'boolean',
            'ai_eligible' => 'boolean',
            'ai_confidence' => 'float',
            'ai_needs_review' => 'boolean',
            'is_sample' => 'boolean',
        ];
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MerchantApplicationEvent::class)->orderBy('id');
    }

    /** 1 to 5, the step the applicant is on now (waiting for it, or done at 5). */
    public function stepNumber(): int
    {
        return match ($this->status) {
            self::APPLIED => 2,
            self::ELIGIBILITY_CHECKED, self::DECLINED => 3,
            self::BID_APPROVED => 4,
            self::STRIPE_ONBOARDING => 4,
            self::LIVE => 5,
            default => 1,
        };
    }

    /** "Step 3 of 5: waiting for BID approval." */
    public function stepSentence(): string
    {
        return match ($this->status) {
            self::APPLIED => 'Step 2 of 5: waiting for the eligibility check.',
            self::ELIGIBILITY_CHECKED => 'Step 3 of 5: waiting for BID approval.',
            self::DECLINED => 'Step 3 of 5: the BID declined this application.',
            self::BID_APPROVED => 'Step 4 of 5: approved. Set up payouts with Stripe.',
            self::STRIPE_ONBOARDING => 'Step 4 of 5: finishing Stripe payouts set-up.',
            self::LIVE => 'Step 5 of 5: live. Employees can spend credits here.',
            default => 'Step 1 of 5.',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::APPLIED => 'Waiting for the eligibility check',
            self::ELIGIBILITY_CHECKED => 'Waiting for BID approval',
            self::BID_APPROVED => 'Approved, payouts not set up',
            self::DECLINED => 'Declined',
            self::STRIPE_ONBOARDING => 'Setting up Stripe payouts',
            self::LIVE => 'Live',
            default => $this->status,
        };
    }

    /** "f…@example.com": who it is, without the address, for anyone who isn't an admin. */
    public function maskedEmail(): string
    {
        [$local, $domain] = array_pad(explode('@', $this->contact_email, 2), 2, '');

        return mb_substr($local, 0, 1).'…@'.$domain;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }
}
