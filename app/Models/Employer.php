<?php

namespace App\Models;

use App\Enums\Occasion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employer extends Model
{
    protected $fillable = [
        'bid_id', 'name', 'monthly_allowance_cents', 'staff_count',
        'monthly_budget_cents', 'welcome_cents', 'birthday_cents', 'recognition_cents',
    ];

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
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
}
