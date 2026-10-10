<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = ['employer_id', 'display_name'];

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    /**
     * The balance is always computed from the ledger, never stored.
     */
    public function balanceCents(): int
    {
        return (int) $this->ledgerEntries()->sum('amount_cents');
    }
}
