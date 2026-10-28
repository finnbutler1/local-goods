<?php

namespace App\Models;

use App\Enums\Occasion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employer extends Model
{
    protected $fillable = [
        'bid_id', 'district_id', 'name', 'monthly_allowance_cents', 'staff_count',
        'monthly_budget_cents', 'welcome_cents', 'birthday_cents', 'recognition_cents',
    ];

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    /** The district its credit program runs in (ADR 0031): credits are spent only at approved, live merchants there. */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }

    public function creditPurchases(): HasMany
    {
        return $this->hasMany(CreditPurchase::class);
    }

    public function creditAllocations(): HasMany
    {
        return $this->hasMany(CreditAllocation::class);
    }

    /** What this occasion gives each employee, or null when the program has it switched off. */
    public function occasionCents(Occasion $occasion): ?int
    {
        $cents = $this->{$occasion->column()};

        return $cents === null ? null : (int) $cents;
    }

    /** @return list<Occasion> Monthly plus every optional occasion that has an amount. */
    public function activeOccasions(): array
    {
        return array_values(array_filter(Occasion::cases(), fn (Occasion $o) => $this->occasionCents($o) !== null));
    }

    /**
     * Credits bought but not yet handed to employees, computed from the ledger.
     */
    public function balanceCents(): int
    {
        return (int) $this->ledgerEntries()->sum('amount_cents');
    }

    /** Corrections asked for by this employer: refunds of unspent credits (ADR 0028). */
    public function corrections(): HasMany
    {
        return $this->hasMany(Correction::class);
    }

    /** The newest correction asked for about this, shown on it. */
    public function latestCorrection(): HasOne
    {
        return $this->hasOne(Correction::class)->latestOfMany();
    }
}
