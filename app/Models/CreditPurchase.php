<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The employer bought credits. A pending purchase is only a request: it has
 * no ledger entry, so its credits can't be handed out or spent. Once paid
 * (simulated until Stripe test mode) the amount is a ledger entry on the
 * employer's account. See CreditLedger::requestPurchase and payPurchase.
 */
class CreditPurchase extends Model
{
    public const STATUS_PENDING = 'pending';

    /** Paid with no real money: the demo's "Pay now" button and the seed. */
    public const STATUS_SIMULATED = 'simulated';

    protected $fillable = ['employer_id', 'amount_cents', 'reference', 'purchased_at', 'status'];

    protected function casts(): array
    {
        return ['purchased_at' => 'datetime'];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class);
    }
}
