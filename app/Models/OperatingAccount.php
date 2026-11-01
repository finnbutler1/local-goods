<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Local Goods Operating (ADR 0034): the connected account Local Goods set up
 * for its own money, in Stripe test mode, one per environment. It holds only
 * fees swept out of the customer funds account. It has no bank account, so
 * Stripe never pays it out. Kept by Reset demo; never deleted.
 */
class OperatingAccount extends Model
{
    public const LABEL = 'Local Goods Operating: an account Local Goods set up for its own money, in Stripe test mode.';

    protected $fillable = ['stripe_account_id', 'setup_key', 'transfers_active'];

    protected function casts(): array
    {
        return ['transfers_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('The operating account record is never deleted.'));
    }

    /** This environment's operating account, or null before it is created. */
    public static function current(): ?self
    {
        return self::orderBy('id')->first();
    }
}
