<?php

namespace Tests\Unit;

use App\Support\SalesTax;
use App\Support\TaxBreakdown;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/** DC sales tax for display (ADR 0027): rates by date, rounding, baskets. */
class SalesTaxTest extends TestCase
{
    private function dc(string $time): Carbon
    {
        return Carbon::parse($time, 'America/New_York');
    }

    private function item(int $price, int $quantity, ?string $category): array
    {
        return ['name' => 'Thing', 'unit_price_cents' => $price, 'quantity' => $quantity, 'tax_category' => $category];
    }

    public function test_the_rates_in_effect_today(): void
    {
        $today = $this->dc('2026-10-24 12:00');

        $this->assertSame(1000, SalesTax::rateBps('prepared_food', $today));
        $this->assertSame(600, SalesTax::rateBps('general', $today));
        $this->assertSame(0, SalesTax::rateBps('exempt', $today));
    }

    public function test_the_general_rate_changes_at_midnight_dc_time_on_1_october_2027(): void
    {
        $this->assertSame(600, SalesTax::rateBps('general', $this->dc('2027-09-30 23:59:59')));
        $this->assertSame(700, SalesTax::rateBps('general', $this->dc('2027-10-01 00:00:00')));
        // 03:59 UTC on 1 October is still 30 September in DC.
        $this->assertSame(600, SalesTax::rateBps('general', Carbon::parse('2027-10-01 03:59:00', 'UTC')));
        $this->assertSame(1000, SalesTax::rateBps('prepared_food', $this->dc('2027-10-01 00:00:00')));
    }

    public function test_an_unknown_category_or_a_date_before_any_rate_never_falls_back_to_zero(): void
    {
        $this->assertThrowsInvalid(fn () => SalesTax::rateBps('groceries', $this->dc('2026-10-24')));
        $this->assertThrowsInvalid(fn () => SalesTax::rateBps('general', $this->dc('2020-01-01')));
    }

    public function test_rounding_is_half_up_to_the_cent(): void
    {
        $this->assertSame(58, SalesTax::on(575, 1000));  // 57.5 cents
        $this->assertSame(1, SalesTax::on(5, 1000));     // half a cent
        $this->assertSame(0, SalesTax::on(4, 1000));
        $this->assertSame(8, SalesTax::on(125, 600));    // 7.5 cents
        $this->assertSame(7, SalesTax::on(124, 600));    // 7.44 cents
        $this->assertSame(0, SalesTax::on(10000, 0));
    }

    public function test_the_example_latte(): void
    {
        $tax = SalesTax::forItems([$this->item(575, 1, 'prepared_food')], $this->dc('2026-10-24 09:00'));

        $this->assertSame([575, 58, 633], [$tax->subtotalCents, $tax->taxCents, $tax->totalCents]);
        $this->assertSame([['category' => 'prepared_food', 'label' => 'DC meals tax', 'rate_bps' => 1000, 'taxable_cents' => 575, 'tax_cents' => 58]], $tax->lines);
        $this->assertSame(1000, $tax->items[0]['tax_rate_bps']);
    }

    public function test_tax_is_worked_out_on_each_categorys_subtotal_not_per_line(): void
    {
        // Three 5-cent items: per line would be 1 + 1 + 1 = 3 cents; on the subtotal (15 cents) it is 1.5, so 2.
        $tax = SalesTax::forItems([$this->item(5, 1, 'prepared_food'), $this->item(5, 1, 'prepared_food'), $this->item(5, 1, 'prepared_food')], $this->dc('2026-10-24'));

        $this->assertSame(2, $tax->taxCents);
        $this->assertCount(1, $tax->lines);
    }

    public function test_a_basket_with_several_categories_and_an_other_amount(): void
    {
        $tax = SalesTax::forItems([
            $this->item(575, 2, 'prepared_food'), // 1150 → 115
            $this->item(1800, 1, 'general'),      // 1800 → 108
            $this->item(300, 1, 'exempt'),        // 300 → 0
            $this->item(250, 1, null),            // Other amount: tax included, none added
        ], $this->dc('2026-10-24'));

        $this->assertSame(3500, $tax->subtotalCents);
        $this->assertSame(223, $tax->taxCents);
        $this->assertSame(3723, $tax->totalCents);
        $this->assertSame(['prepared_food', 'general', 'exempt'], array_column($tax->lines, 'category'));
        $this->assertSame([115, 108, 0], array_column($tax->lines, 'tax_cents'));
        $this->assertNull($tax->items[3]['tax_rate_bps']);
    }

    public function test_the_same_basket_after_the_2027_change(): void
    {
        $tax = SalesTax::forItems([$this->item(1800, 1, 'general')], $this->dc('2027-10-01 08:00'));

        $this->assertSame(126, $tax->taxCents);
        $this->assertSame(700, $tax->lines[0]['rate_bps']);
    }

    public function test_an_exempt_only_basket_adds_nothing(): void
    {
        $tax = SalesTax::forItems([$this->item(4200, 1, 'exempt'), $this->item(2200, 1, 'exempt')], $this->dc('2026-10-24'));

        $this->assertSame(TaxBreakdown::ADDED, $tax->basis);
        $this->assertSame([6400, 0, 6400], [$tax->subtotalCents, $tax->taxCents, $tax->totalCents]);
    }

    public function test_a_typed_in_amount_has_tax_included_and_nothing_split_out(): void
    {
        $tax = TaxBreakdown::included(1234);

        $this->assertSame(1234, $tax->totalCents);
        $this->assertSame(['tax_basis' => 'included', 'subtotal_cents' => null, 'tax_cents' => null, 'tax_lines' => null], $tax->columns());
    }

    public function test_a_breakdown_that_does_not_add_up_is_refused(): void
    {
        $this->expectException(LogicException::class);
        new TaxBreakdown(TaxBreakdown::ADDED, 700, 575, 58);
    }

    public function test_stored_columns_round_trip(): void
    {
        $tax = SalesTax::forItems([$this->item(575, 1, 'prepared_food')], $this->dc('2026-10-24'));
        $stored = TaxBreakdown::fromStored(['amount_cents' => 633] + $tax->columns());

        $this->assertEquals([$tax->subtotalCents, $tax->taxCents, $tax->lines], [$stored->subtotalCents, $stored->taxCents, $stored->lines]);
        $this->assertNull(TaxBreakdown::fromStored(['amount_cents' => 633, 'tax_basis' => null]));
    }

    public function test_the_config_is_integer_dated_sourced_and_in_order(): void
    {
        $this->assertSame(['prepared_food', 'general', 'exempt'], SalesTax::categories());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', config('tax.verified_on'));
        $this->assertNotEmpty(config('tax.sources'));

        foreach (config('tax.sources') as $source) {
            $this->assertStringStartsWith('https://', $source['url']);
            $this->assertMatchesRegularExpression('#^https://(otr\.cfo\.dc\.gov|code\.dccouncil\.gov)/#', $source['url']);
        }

        foreach (config('tax.categories') as $category) {
            $dates = array_column($category['rates'], 'effective_from');
            $sorted = $dates;
            sort($sorted);
            $this->assertSame($sorted, $dates);

            foreach ($category['rates'] as $rate) {
                $this->assertIsInt($rate['rate_bps']);
            }
        }

        foreach (config('tax.merchant_category_defaults') as $default) {
            $this->assertContains($default, SalesTax::categories());
        }
    }

    public function test_percent_labels(): void
    {
        $this->assertSame('10%', TaxBreakdown::percent(1000));
        $this->assertSame('6%', TaxBreakdown::percent(600));
        $this->assertSame('6.5%', TaxBreakdown::percent(650));
        $this->assertSame('0%', TaxBreakdown::percent(0));
    }

    private function assertThrowsInvalid(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
