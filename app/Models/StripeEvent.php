<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Stripe webhook event Local Goods has handled. Its id is unique, so the
 * same event delivered twice (Stripe retries, or a replay) is handled once.
 */
class StripeEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['stripe_event_id', 'type', 'outcome', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
