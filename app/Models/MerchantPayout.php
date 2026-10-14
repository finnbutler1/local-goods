<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money paid out to a merchant for cleared redemptions. Stripe Connect is not
 * built yet, so payouts in the demo are "simulated": recorded in the ledger,
 * with no Stripe transfer behind them.
 */
class MerchantPayout extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SIMULATED = 'simulated';

    protected $fillable = ['merchant_id', 'amount_cents', 'status', 'stripe_transfer_id', 'paid_at'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
