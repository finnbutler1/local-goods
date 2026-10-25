<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\MerchantPayout;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\Payments\StripeReconciliation;
use App\Support\StripeEnvironment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SetsMerchantFee;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Reconciliation with a merchant fee (ADR 0029): transfers are net of the
 * fee, so everything still matches Stripe, the expected platform balance
 * (purchases − net transfers − refunds) includes the fees kept, and a
 * transfer or fee line that doesn't add up is named.
 */
class MerchantFeeReconciliationTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use SetsMerchantFee;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->withoutSeededCorrections();
        $this->fakeStripe();
        $this->stripePurchase(50000);
        $this->onboard();
        $this->setMerchantFee(250);
        foreach (CreditPurchase::where('status', 'paid')->get() as $p) {
            $this->stripe->paymentIntents[] = ['id' => $p->stripe_payment_intent_id, 'status' => 'succeeded', 'amount' => $p->totalCents(), 'amount_received' => $p->totalCents(),
                'currency' => 'usd', 'metadata' => ['lg_purchase_token' => $p->payment_token ?? 'tok-'.$p->id] + StripeEnvironment::tag()];
        }
    }

    private function report(): array
    {
        return app(StripeReconciliation::class)->report();
    }

    public function test_everything_matches_with_a_fee_and_the_fee_is_in_the_expected_balance(): void
    {
        $redemption = $this->cleared(633);

        $report = $this->report();
        $this->assertSame([], $report['mismatches']);
        $this->assertSame([617, 617, 16], [$report['ledger']['transfer_cents'], $report['stripe']['transfer_cents'], $report['ledger']['merchant_fee_cents']]);
        $this->assertSame(50000 - 617, $report['expected_balance_cents'], 'purchases − net transfers − refunds, the 16¢ fee included');

        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/reconciliation')->assertOk()->assertSee('data-reconciled="yes"', false)
            ->assertSee('includes $0.16 Local Goods fees kept on merchant sales');

        // A partial refund: still matches once Stripe confirms; the fee kept goes down by the share.
        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Refund.', amountCents: 211);
        $this->postWebhook($this->reversedEvent($redemption->stripePayout))->assertOk();

        $after = $this->report();
        $this->assertSame([], $after['mismatches']);
        $this->assertSame([411, 411, 11], [$after['ledger']['transfer_cents'], $after['stripe']['transfer_cents'], $after['ledger']['merchant_fee_cents']]);
        $this->assertSame(50000 - 411, $after['expected_balance_cents']);
        $this->assertLedgerInvariant();
    }

    public function test_a_transfer_that_does_not_add_up_with_its_fee_is_named(): void
    {
        $redemption = $this->cleared(633);
        DB::table('merchant_payouts')->where('redemption_id', $redemption->id)->update(['fee_cents' => 10]);

        $this->assertTrue(collect($this->report()['mismatches'])->contains(fn ($m) => str_contains($m, 'plus the Local Goods fee held back ($0.10)')));
    }

    public function test_a_platform_fee_line_without_a_sale_is_named(): void
    {
        $this->cleared(633);
        CreditLedgerEntry::create(['platform' => true, 'amount_cents' => 5, 'type' => CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE, 'description' => 'Stray line']);

        $this->assertContains('Local Goods fees on merchant sales: cleared sales charged $0.16 and refunds gave back $0.00, but the platform account in the ledger has $0.21 for them.', $this->report()['mismatches']);
    }

    public function test_with_no_fee_the_page_says_nothing_about_fees(): void
    {
        $this->setMerchantFee(0);
        $this->cleared(633);

        $this->assertSame([], $this->report()['mismatches']);
        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/reconciliation')->assertOk()->assertDontSee('data-merchant-fees', false);
        $this->assertSame(0, MerchantPayout::where('fee_cents', '>', 0)->count());
    }
}
