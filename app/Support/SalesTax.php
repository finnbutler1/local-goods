<?php

namespace App\Support;

use App\Models\Merchant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * DC sales tax for display at redemption (ADR 0027). Rates and their dates
 * live in config/tax.php; this only looks them up and adds up. Integer cents
 * and basis points throughout, tax worked out per category on that
 * category's subtotal and rounded half up to the cent. Not tax advice: the
 * merchant files and remits the tax.
 */
class SalesTax
{
    /** @return list<string> */
    public static function categories(): array
    {
        return array_keys(config('tax.categories'));
    }

    public static function label(string $category): string
    {
        return self::category($category)['label'];
    }

    public static function name(string $category): string
    {
        return self::category($category)['name'];
    }

    /** The rate in effect at $at (DC time), in basis points. Throws for an unknown category or a date before the first rate. */
    public static function rateBps(string $category, ?CarbonInterface $at = null): int
    {
        $day = Carbon::instance($at ?? now())->setTimezone(config('tax.timezone'))->toDateString();
        $rate = null;

        foreach (self::category($category)['rates'] as $row) {
            if ($row['effective_from'] <= $day) {
                $rate = (int) $row['rate_bps'];
            }
        }

        if ($rate === null) {
            throw new InvalidArgumentException("No {$category} tax rate is in effect on {$day}.");
        }

        return $rate;
    }

    /** The category a merchant's new products start with. */
    public static function defaultFor(Merchant $merchant): string
    {
        return config('tax.merchant_category_defaults')[$merchant->category] ?? config('tax.fallback_category');
    }

    /** Tax on this many cents at this rate, rounded half up to a cent. */
    public static function on(int $cents, int $rateBps): int
    {
        return intdiv($cents * $rateBps + 5000, 10000);
    }

    /**
     * Tax added on top of menu items. Each item has unit_price_cents,
     * quantity and tax_category (null for an "Other amount", which already
     * includes any tax and gets none added). Returns the items with
     * tax_rate_bps filled in and one line per category, in config order.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function forItems(array $items, ?CarbonInterface $at = null): TaxBreakdown
    {
        $taxable = [];
        $subtotal = 0;

        foreach ($items as $i => $item) {
            $cents = $item['unit_price_cents'] * $item['quantity'];
            $subtotal += $cents;
            $category = $item['tax_category'] ?? null;
            $items[$i]['tax_rate_bps'] = $category === null ? null : self::rateBps($category, $at);

            if ($category !== null) {
                $taxable[$category] = ($taxable[$category] ?? 0) + $cents;
            }
        }

        $lines = [];

        foreach (self::categories() as $category) {
            if (! isset($taxable[$category])) {
                continue;
            }

            $rate = self::rateBps($category, $at);
            $lines[] = [
                'category' => $category,
                'label' => self::label($category),
                'rate_bps' => $rate,
                'taxable_cents' => $taxable[$category],
                'tax_cents' => self::on($taxable[$category], $rate),
            ];
        }

        $tax = array_sum(array_column($lines, 'tax_cents'));

        return new TaxBreakdown(TaxBreakdown::ADDED, $subtotal + $tax, $subtotal, $tax, $lines, $items);
    }

    private static function category(string $category): array
    {
        return config("tax.categories.{$category}") ?? throw new InvalidArgumentException("Unknown tax category {$category}.");
    }
}
