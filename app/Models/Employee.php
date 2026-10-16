<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = ['employer_id', 'display_name', 'user_id'];

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * "Hayden Marsh" becomes "H.M.". Outside the employee's own wallet,
     * pages show this, never the full name (ADR 0014).
     */
    public function initials(): string
    {
        $name = trim(preg_replace('/\s*\(.*?\)\s*/u', ' ', $this->display_name));

        return collect(preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)).'.')
            ->implode('');
    }

    /** Initials plus a staff number, e.g. "H.M. · #014", so two people with the same initials can be told apart. */
    public function publicLabel(): string
    {
        return $this->initials().' · #'.str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The balance is always computed from the ledger, never stored.
     */
    public function balanceCents(): int
    {
        return (int) $this->ledgerEntries()->sum('amount_cents');
    }
}
