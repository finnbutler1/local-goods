<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The employer bought credits. The amount is also a ledger entry on the employer's account. */
class CreditPurchase extends Model
{
    protected $fillable = ['employer_id', 'amount_cents', 'reference', 'purchased_at'];

    protected function casts(): array
    {
        return ['purchased_at' => 'datetime'];
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class);
    }
}
