<?php

namespace App\Models;

use App\Support\SalesTax;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One product on a demo merchant's menu (ADR 0023). Display only: a
 * redemption copies the name and price into its items, and nothing about
 * products is sent to Redemption Fraud Detection. Only the fictional demo
 * merchants may have products, never a real candidate.
 */
class MerchantProduct extends Model
{
    protected $fillable = ['merchant_id', 'name', 'price_cents', 'description', 'position', 'is_available', 'tax_category'];

    protected function casts(): array
    {
        return ['is_available' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $product) {
            if (! Merchant::find($product->merchant_id)?->mayHaveProducts()) {
                throw new LogicException('Only the fictional demo merchants have products.');
            }
        });
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** prepared_food, general or exempt (ADR 0027); the merchant's default if never set. */
    public function taxCategory(): string
    {
        return $this->tax_category ?? SalesTax::defaultFor($this->merchant);
    }

    /** @param  Builder<self>  $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_available', true);
    }
}
