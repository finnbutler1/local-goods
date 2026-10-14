<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    protected $fillable = ['bid_id', 'name', 'category', 'address'];

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
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
}
