<?php

namespace Tests\Feature;

use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\CreditLedger;
use App\Services\Payments\StripeReconciliation;
use App\Services\Solvency\SolvencyCheck;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Simulated money (all seeded history, and everything when Stripe is off)
 * follows the same rule as Stripe test-mode money (ADR 0033): simulated
 * purchases count as money in, simulated payouts as money out. It never
 * counts toward the Stripe floor. A refund on a simulated sale that was
 * already paid out is pulled back (simulated), so the merchant never goes
 * below zero (this replaces ADR 0028's netting below zero).
 */
class SolvencySimulatedModeTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_simulated_purchases_and_payouts_count_as_money_but_never_as_stripe_money(): void
    {
        $now = app(SolvencyCheck::class)->now();

        $this->assertSame((int) CreditPurchase::where('status', CreditPurchase::STATUS_SIMULATED)->sum('amount_cents'), $now->creditsPaidInCents);
        $this->assertSame((int) MerchantPayout::where('status', MerchantPayout::STATUS_SIMULATED)->sum('amount_cents'), $now->paidOutCents);
        $this->assertSame([0, 0], [$now->stripeInCents, $now->stripeOutCents], 'Seeded history is never Stripe money');
        $this->assertTrue($now->passes(), (string) $now->problem());

        $purchase = app(CreditLedger::class)->requestPurchase(Employer::firstOrFail(), 25000, 'Simulated test');
        $this->assertSame($now->inHandCents(), app(SolvencyCheck::class)->now()->inHandCents(), 'A pending purchase is not money in');
        app(CreditLedger::class)->payPurchase($purchase);
        $after = app(SolvencyCheck::class)->now();
        $this->assertSame($now->inHandCents() + 25000, $after->inHandCents());
        $this->assertSame($now->employerCents + 25000, $after->employerCents, 'And the same amount promised back');
        $this->assertSame(0, $after->stripeInCents);
        $this->assertTrue($after->passes());
    }

    public function test_a_simulated_refund_after_a_simulated_payout_leaves_the_merchant_at_zero_not_below(): void
    {
        $this->fakeStripe();
        $merchant = $this->farragut();
        $redemption = Redemption::where('merchant_id', $merchant->id)->where('scored_by', 'seed')->doesntHave('items')
            ->where('created_at', '<', MerchantPayout::where('merchant_id', $merchant->id)->max('paid_at'))->orderByDesc('id')->firstOrFail();

        // Pay the shop everything it is owed, simulated, as the seed's weekly payouts do.
        app(CreditLedger::class)->payout($merchant, $merchant->owedSimulatedCents(), MerchantPayout::STATUS_SIMULATED);
        $this->assertSame(0, $merchant->payableCents());
        $payout = MerchantPayout::where('merchant_id', $merchant->id)->orderByDesc('id')->firstOrFail();
        $reconciliation = Arr::except(app(StripeReconciliation::class)->report(), ['since']);

        $correction = app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Never made.', amountCents: $redemption->amount_cents);

        $this->assertSame(Correction::STATUS_COMPLETED, $correction->status);
        $this->assertSame(0, $merchant->fresh()->payableCents(), 'At zero, not below');
        $pullBack = CreditLedgerEntry::where('correction_id', $correction->id)->where('type', CreditLedgerEntry::TYPE_REFUND_REVERSAL)->sole();
        $this->assertSame($redemption->amount_cents, $pullBack->amount_cents);
        $this->assertSame($payout->id, $pullBack->merchant_payout_id);
        $this->assertStringContainsString('(simulated, no real money)', $pullBack->description);
        $this->assertStringContainsString('pulled back from simulated payout #'.$payout->id, CreditLedgerEntry::where('correction_id', $correction->id)->where('type', CreditLedgerEntry::TYPE_MERCHANT_REFUNDED)->sole()->description);
        $this->assertSame([[CorrectionAction::KIND_SIMULATED, $payout->id]], $correction->actions->map(fn ($a) => [$a->kind, $a->merchant_payout_id])->all());

        $check = app(SolvencyCheck::class);
        $this->assertTrue($check->now()->passes(), (string) $check->now()->problem());
        $this->assertTrue(($history = $check->history())->passed(), $history->summary());
        $after = Arr::except(app(StripeReconciliation::class)->report(), ['since']);
        $this->assertSame(Arr::except($reconciliation, ['simulated']), Arr::except($after, ['simulated']), 'Stripe reconciliation ignores a simulated pull-back');
        $this->assertSame(Arr::except($reconciliation['simulated'], ['refunds', 'refund_cents']), Arr::except($after['simulated'], ['refunds', 'refund_cents']), 'Simulated payouts are unchanged');
        $this->assertSame([$reconciliation['simulated']['refunds'] + 1, $reconciliation['simulated']['refund_cents'] + $redemption->amount_cents],
            [$after['simulated']['refunds'], $after['simulated']['refund_cents']], 'Only the refund itself is counted, as a simulated refund');
        $this->assertLedgerInvariant();
    }

    public function test_only_the_part_already_paid_out_is_pulled_back(): void
    {
        $merchant = $this->farragut();
        $redemption = $this->cleared(1200);
        app(CreditLedger::class)->payout($merchant, $merchant->owedSimulatedCents() - 500, MerchantPayout::STATUS_SIMULATED);
        $this->assertSame(500, $merchant->owedSimulatedCents());

        $correction = app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Never made.', amountCents: 1200);

        $this->assertSame(0, $merchant->fresh()->payableCents());
        $this->assertSame(700, (int) CreditLedgerEntry::where('correction_id', $correction->id)->where('type', CreditLedgerEntry::TYPE_REFUND_REVERSAL)->sum('amount_cents'));
        $this->assertTrue(app(SolvencyCheck::class)->history()->passed());
        $this->assertLedgerInvariant();
    }

    public function test_a_shop_owed_enough_is_deducted_as_before_with_no_pull_back(): void
    {
        $redemption = $this->cleared(1200);
        $owed = $this->farragut()->payableCents();

        $correction = app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Never made.', amountCents: 400);

        $this->assertSame($owed - 400, $this->farragut()->payableCents());
        $this->assertSame(0, CreditLedgerEntry::where('correction_id', $correction->id)->where('type', CreditLedgerEntry::TYPE_REFUND_REVERSAL)->count());
        $this->assertNull($correction->actions->sole()->merchant_payout_id);
    }

    public function test_a_refund_never_eats_into_what_waits_for_a_stripe_transfer(): void
    {
        $merchant = $this->farragut();
        $redemption = $this->cleared(1000);
        app(CreditLedger::class)->payout($merchant, $merchant->owedSimulatedCents(), MerchantPayout::STATUS_SIMULATED);
        // $30.00 owed to the shop through Stripe, not sent yet.
        MerchantPayout::create(['merchant_id' => $merchant->id, 'amount_cents' => 3000, 'status' => MerchantPayout::STATUS_OWED]);
        // Paid for from the employer's unspent credits, so the books still balance.
        DB::table('credit_ledger_entries')->insert([
            ['employer_id' => Employer::firstOrFail()->id, 'merchant_id' => null, 'amount_cents' => -3000, 'type' => CreditLedgerEntry::TYPE_ALLOCATION_OUT, 'description' => 'Test: spent', 'created_at' => now()],
            ['employer_id' => null, 'merchant_id' => $merchant->id, 'amount_cents' => 3000, 'type' => CreditLedgerEntry::TYPE_MERCHANT_EARNED, 'description' => 'Test: owed via Stripe', 'created_at' => now()],
        ]);
        $this->assertTrue(app(SolvencyCheck::class)->now()->passes());

        $correction = app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Never made.', amountCents: 1000);

        $this->assertSame(1000, (int) CreditLedgerEntry::where('correction_id', $correction->id)->where('type', CreditLedgerEntry::TYPE_REFUND_REVERSAL)->sum('amount_cents'), 'All of it pulled back');
        $this->assertSame(3000, $merchant->fresh()->payableCents(), 'The Stripe-owed $30 is still owed in full');
    }

    public function test_a_planted_stripe_transfer_with_no_stripe_money_behind_it_breaks_the_stripe_floor(): void
    {
        $merchant = Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail();
        $row = MerchantPayout::create(['merchant_id' => $merchant->id, 'amount_cents' => 500, 'status' => MerchantPayout::STATUS_SENT, 'stripe_transfer_id' => 'tr_test_planted', 'paid_at' => now()]);
        DB::table('credit_ledger_entries')->insert(['merchant_id' => $merchant->id, 'amount_cents' => -500, 'type' => CreditLedgerEntry::TYPE_PAYOUT,
            'merchant_payout_id' => $row->id, 'description' => 'Planted by a test', 'created_at' => now()]);

        $now = app(SolvencyCheck::class)->now();
        $this->assertTrue($now->isSolvent(), 'The shop was owed it, so the overall rule still holds');
        $this->assertFalse($now->stripeFloorHolds());
        $this->assertSame(500, $now->stripeShortfallCents());
        $this->assertStringContainsString('Stripe money out is above Stripe money in by $5.00', app(SolvencyCheck::class)->history()->summary());
    }
}
