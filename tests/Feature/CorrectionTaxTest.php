<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Merchant\RedemptionsExportController;
use App\Models\MerchantPayout;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\MerchantTaxSummary;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * A refunded taxed order lowers the merchant's "Tax collected" (ADR 0028)
 * in the month the refund completed, by the tax on the refunded items from
 * the order's own snapshot. The CSV shows each completed refund as its own
 * row with negative amounts. Typed-in amounts never split out tax.
 */
class CorrectionTaxTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function thisMonth(): array
    {
        return MerchantTaxSummary::for($this->farragut())['this_month'];
    }

    public function test_a_refund_of_taxed_items_comes_off_tax_collected_this_month(): void
    {
        $redemption = $this->clearedOrder(['Drip coffee' => 2]);
        $before = $this->thisMonth();

        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'One coffee.', [$redemption->items->sole()->id => 1]);

        $after = $this->thisMonth();
        $this->assertSame($before['tax_cents'], $after['tax_cents'], 'Tax added at checkout is unchanged');
        $this->assertSame([38, 1], [$after['refunded_tax_cents'], $after['refunds']]);
        $this->assertSame($before['net_tax_cents'] - 38, $after['net_tax_cents']);

        $this->signIn(Role::Merchant);
        $this->get(route('merchant.dashboard'))->assertOk()
            ->assertSee('data-tax-month="this_month">'.Money::format($after['net_tax_cents']), false)
            ->assertSee('less $0.38 refunded on 1 refund');
    }

    public function test_the_tax_comes_off_in_the_month_the_refund_completed(): void
    {
        $this->travelTo(now()->setTimezone('America/New_York')->startOfMonth()->addDays(3)->setTime(12, 0));
        $redemption = $this->clearedOrder(['Latte' => 1]);
        $ordered = $this->thisMonth();

        $this->travel(1)->months();
        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Wrong drink.', [$redemption->items->sole()->id => 1]);

        $summary = MerchantTaxSummary::for($this->farragut());
        $this->assertSame([$ordered['tax_cents'], 0], [$summary['last_month']['tax_cents'], $summary['last_month']['refunded_tax_cents']], 'The order\'s month keeps its tax');
        $this->assertSame(58, $summary['this_month']['refunded_tax_cents']);
    }

    public function test_a_typed_in_refund_and_an_unconfirmed_stripe_refund_change_no_tax(): void
    {
        $before = $this->thisMonth();
        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($this->cleared(900)), $this->admin(), 'Typed wrong.', amountCents: 300);
        $this->assertSame($before, $this->thisMonth());

        $this->fakeStripe();
        $this->stripePurchase();
        $this->onboard();
        $order = $this->clearedOrder(['Latte' => 1]);
        $this->assertSame(MerchantPayout::STATUS_SENT, MerchantPayout::where('redemption_id', $order->id)->value('status'));
        $withOrder = $this->thisMonth();
        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($order), $this->admin(), 'Wrong drink.', [$order->items->sole()->id => 1]);

        $this->assertSame(0, $this->thisMonth()['refunded_tax_cents'], 'Not until Stripe confirms');
        $this->postWebhook($this->reversedEvent(MerchantPayout::where('redemption_id', $order->id)->sole()))->assertOk();
        $this->assertSame($withOrder['net_tax_cents'] - 58, $this->thisMonth()['net_tax_cents']);
    }

    public function test_the_csv_has_a_row_for_each_completed_refund(): void
    {
        $redemption = $this->clearedOrder(['Drip coffee' => 2]);
        $this->travel(5)->minutes();
        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'One coffee.', [$redemption->items->sole()->id => 1]);

        $this->signIn(Role::Merchant);
        $rows = array_map(str_getcsv(...), array_filter(explode("\n", $this->get(route('merchant.redemptions.csv'))->streamedContent())));
        $refund = collect($rows)->first(fn ($r) => ($r[3] ?? null) === 'Refund');

        $this->assertSame([$redemption->reference(), $redemption->employee->initials(), '1× Drip coffee', '-3.75', '-0.38', '-4.13', 'Refunded tax: DC meals tax 10% $0.38'],
            [$refund[1], $refund[4], $refund[5], $refund[6], $refund[7], $refund[8], $refund[9]]);
        $this->assertStringNotContainsString($redemption->employee->display_name, implode(',', array_merge(...$rows)));
        $this->assertSame('Refund', $rows[1][3], 'The newest row first');
    }

    public function test_negative_amounts_stay_numbers_but_formulas_are_still_stopped(): void
    {
        $this->assertSame('-4.13', RedemptionsExportController::cell('-4.13'));
        $this->assertSame("'-A1+1", RedemptionsExportController::cell('-A1+1'));
        $this->assertSame("'-1+1", RedemptionsExportController::cell('-1+1'));
    }
}
