<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\RedemptionItem;
use App\Support\SalesTax;
use App\Support\TaxBreakdown;
use Illuminate\Validation\ValidationException;

/**
 * Turns what an employee picked from a merchant's menu (ADR 0023) into the
 * redemption total and its item lines. The server works out the total from
 * the menu's own prices and the DC sales tax in effect now (ADR 0027);
 * nothing the browser adds up is trusted. Tax is added on menu items only:
 * an "Other amount" or a bare typed-in amount is the shop's own total with
 * any tax already in it. Display only: the items and tax never reach
 * Redemption Fraud Detection, which sees just the total.
 */
class RedemptionBasket
{
    public const MAX_QUANTITY = 20;

    /**
     * @param  array<int|string, mixed>  $quantities  product id => quantity (0 or blank = not chosen)
     * @param  int  $otherCents  the "Other amount" part, 0 if none
     * @return array{total_cents: int, items: list<array{merchant_product_id: ?int, name: string, unit_price_cents: int, quantity: int, tax_category: ?string, tax_rate_bps: ?int}>, tax: TaxBreakdown}
     */
    public static function build(Merchant $merchant, array $quantities, int $otherCents): array
    {
        $chosen = array_filter($quantities, fn ($q) => (int) $q > 0);
        $products = $merchant->products()->available()->whereKey(array_keys($chosen))->get()->keyBy('id');

        if ($products->count() !== count($chosen)) {
            throw ValidationException::withMessages(['items' => 'One of those items is not on '.$merchant->name.'\'s menu any more. Check your choices and try again.']);
        }

        $items = [];
        foreach ($products as $id => $product) {
            $items[] = ['merchant_product_id' => $id, 'name' => $product->name, 'unit_price_cents' => $product->price_cents, 'quantity' => (int) $chosen[$id], 'tax_category' => $product->taxCategory()];
        }

        // Only an amount with nothing picked stays a bare amount, exactly as before menus.
        if ($items !== [] && $otherCents > 0) {
            $items[] = ['merchant_product_id' => null, 'name' => RedemptionItem::OTHER_AMOUNT, 'unit_price_cents' => $otherCents, 'quantity' => 1, 'tax_category' => null];
        }

        $tax = $items === [] ? TaxBreakdown::included($otherCents) : SalesTax::forItems($items);

        if ($tax->totalCents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Choose at least one item, or enter an other amount.']);
        }

        return ['total_cents' => $tax->totalCents, 'items' => $tax->items, 'tax' => $tax];
    }
}
