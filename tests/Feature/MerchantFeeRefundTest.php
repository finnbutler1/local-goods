<?php

namespace Tests\Feature;

use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\CreditLedgerEntry;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Services\Corrections\CorrectionDecisions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SetsMerchantFee;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * A refund gives back the merchant fee in proportion (ADR 0029): the
 * employee gets the whole refund, the merchant gives back the refund less
 * the fee's share (what Stripe reverses) and the platform gives back the
 * share. Worked out cumulatively, so over every refund exactly the fee
 * comes back and never more.
 */
class MerchantFeeRefundTest extends TestCase
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
    }

    private function refund(Redemption $redemption, int $cents): Correction
    {
        return app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Part of the order was wrong.', amountCents: $cents);
    }

    /** @return array<string, int> ledger type => cents, for one correction */
    private function lines(Correction $correction): array
    {
        return CreditLedgerEntry::where('correction_id', $correction->id)->orderBy('id')->get()
            ->mapWithKeys(fn ($e) => [$e->type => $e->amount_cents])->all();
    }

    private function platformCents(): int
    {
        return (int) CreditLedgerEntry::where('platform', true)->sum('amount_cents');
    }

    public function test_the_worked_example_through_stripe(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(10000);
        $this->onboard();
        $this->setMerchantFee(250);
        $platformBefore = $this->platformCents();
        $redemption = $this->cleared(633);
        $payout = MerchantPayout::where('redemption_id', $redemption->id)->sole();
        $this->assertSame([617, 16], [$payout->amount_cents, $payout->fee_cents]);
        $employeeBefore = $redemption->employee->balanceCents();

        // $2.11: share 5¢ (15.825 × 211 / 633 = 5.33), Stripe reverses $2.06.
        $first = $this->refund($redemption, 211);
        $this->assertSame(5, $first->fee_cents);
        $this->assertSame(206, $first->actions->sole()->amount_cents);
        [$call] = $this->stripe->callsOf('reversal');
        $this->assertSame(206, $call['amount']);
        $this->assertSame([], $this->lines($first), 'Nothing is written until Stripe confirms');

        $this->postWebhook($this->reversedEvent($payout))->assertOk();
        $this->assertSame(Correction::STATUS_COMPLETED, $first->fresh()->status);
        $this->assertSame([
            CreditLedgerEntry::TYPE_REFUND => 211,
            CreditLedgerEntry::TYPE_MERCHANT_REFUNDED => -211,
            CreditLedgerEntry::TYPE_MERCHANT_FEE_RETURNED => 5,
            CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE_RETURNED => -5,
            CreditLedgerEntry::TYPE_REFUND_REVERSAL => 206,
        ], $this->lines($first));
        $this->assertLedgerInvariant();

        // The rest, $4.22: the whole fee is now refunded, so 16 − 5 = 11¢; Stripe reverses $4.11.
        $second = $this->refund($redemption, 422);
        $this->assertSame([11, 411], [$second->fee_cents, $second->actions->sole()->amount_cents]);
        $this->postWebhook($this->reversedEvent($payout))->assertOk();
        $this->assertSame(Correction::STATUS_COMPLETED, $second->fresh()->status);

        $this->assertSame(16, (int) Correction::where('redemption_id', $redemption->id)->sum('fee_cents'), 'Exactly the fee came back');
        $this->assertSame(617, (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_REFUND_REVERSAL)->where('merchant_payout_id', $payout->id)->sum('amount_cents'));
        $this->assertSame(617, $this->stripe->transfer($payout->stripe_transfer_id)['amount_reversed']);
        $this->assertSame($employeeBefore + 633, $redemption->employee->balanceCents());
        $this->assertSame($platformBefore, $this->platformCents(), 'The platform gave its whole fee back');
        $this->assertSame(0, (int) CreditLedgerEntry::where('merchant_id', $this->farragut()->id)->where(fn ($q) => $q->where('redemption_id', $redemption->id)
            ->orWhere('merchant_payout_id', $payout->id))->sum('amount_cents'), 'The merchant nets to zero on this sale');
        $this->assertLedgerInvariant();
    }

    public function test_a_replayed_reversal_webhook_writes_nothing(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(10000);
        $this->onboard();
        $this->setMerchantFee(250);
        $redemption = $this->cleared(633);
        $payout = $redemption->stripePayout;
        $correction = $this->refund($redemption, 633);
        $event = $this->reversedEvent($payout);

        $this->postWebhook($event)->assertOk();
        $count = CreditLedgerEntry::count();
        $this->postWebhook($event)->assertOk();
        $this->postWebhook($this->reversedEvent($payout))->assertOk(); // a new event about the same reversal

        $this->assertSame($count, CreditLedgerEntry::count());
        $this->assertSame(16, $correction->fresh()->fee_cents);
        $this->assertSame(0, CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_PAYOUT_REVERSED)->count(), 'Our net reversal is fully explained');
        $this->assertLedgerInvariant();
    }

    public function test_a_simulated_full_refund_gives_back_the_whole_fee(): void
    {
        $this->setMerchantFee(250, 10);
        $merchantBefore = $this->farragut()->payableCents();
        $platformBefore = $this->platformCents();
        $redemption = $this->cleared(633); // fee 26
        $this->assertSame($merchantBefore + 607, $this->farragut()->payableCents());

        $correction = $this->refund($redemption, 633);

        $this->assertSame(Correction::STATUS_COMPLETED, $correction->status);
        $this->assertSame(26, $correction->fee_cents);
        $this->assertSame([CorrectionAction::KIND_SIMULATED], $correction->actions->pluck('kind')->all());
        $this->assertSame([
            CreditLedgerEntry::TYPE_REFUND => 633,
            CreditLedgerEntry::TYPE_MERCHANT_REFUNDED => -633,
            CreditLedgerEntry::TYPE_MERCHANT_FEE_RETURNED => 26,
            CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE_RETURNED => -26,
        ], $this->lines($correction));
        $this->assertSame($merchantBefore, $this->farragut()->payableCents());
        $this->assertSame($platformBefore, $this->platformCents());
        $this->assertLedgerInvariant();
    }

    public function test_three_partial_refunds_return_exactly_the_fee(): void
    {
        $this->setMerchantFee(1000, 100);
        $redemption = $this->cleared(1001); // 100.1 → 100, + 100 = 200

        $shares = [];
        foreach ([333, 333, 335] as $cents) {
            $shares[] = $this->refund($redemption, $cents)->fee_cents;
        }

        $this->assertSame(200, array_sum($shares));
        $this->assertSame([67, 66, 67], $shares);
        $this->assertLedgerInvariant();
    }

    public function test_a_closed_refund_never_lets_the_rest_return_more_than_the_fee(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(10000);
        $this->onboard();
        $this->setMerchantFee(250);
        $redemption = $this->cleared(633);

        // The first refund's reversal is refused by Stripe and the admin closes it: its share no longer counts.
        $this->stripe->reversalFailures[] = ['Stripe refused the request (test).'];
        $failed = $this->refund($redemption, 211);
        $this->assertSame(Correction::STATUS_FAILED, $failed->status);
        app(CorrectionDecisions::class)->close($failed, $this->admin(), 'Sorted out by hand.');

        $rest = $this->refund($redemption, 633);
        $this->assertSame(16, $rest->fee_cents);
        $this->assertSame(617, $rest->actions->sole()->amount_cents);
    }

    public function test_a_refund_that_is_all_fee_needs_no_stripe_reversal(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(10000);
        $this->onboard();
        $this->setMerchantFee(250);
        $redemption = $this->cleared(633);
        $payout = $redemption->stripePayout;

        $first = $this->refund($redemption, 19); // share 0
        $this->postWebhook($this->reversedEvent($payout))->assertOk();
        $this->assertSame([0, 19], [$first->fresh()->fee_cents, $first->actions->sole()->amount_cents]);

        // The next 1¢ crosses a rounding step: its share is the whole cent, which the merchant never received.
        $reversalsBefore = count($this->stripe->callsOf('reversal'));
        $cent = $this->refund($redemption, 1);

        $this->assertSame(Correction::STATUS_COMPLETED, $cent->status);
        $this->assertSame(1, $cent->fee_cents);
        $this->assertSame([[CorrectionAction::KIND_FEE_ONLY, 0]], $cent->actions->map(fn ($a) => [$a->kind, $a->amount_cents])->all());
        $this->assertCount($reversalsBefore, $this->stripe->callsOf('reversal'), 'No Stripe call');
        $this->assertSame([
            CreditLedgerEntry::TYPE_REFUND => 1,
            CreditLedgerEntry::TYPE_MERCHANT_REFUNDED => -1,
            CreditLedgerEntry::TYPE_MERCHANT_FEE_RETURNED => 1,
            CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE_RETURNED => -1,
        ], $this->lines($cent));
        $this->assertLedgerInvariant();
    }

    public function test_with_no_fee_refund_lines_are_exactly_as_before(): void
    {
        $correction = $this->refund($this->cleared(633), 211);

        $this->assertSame(0, $correction->fee_cents);
        $this->assertSame([CreditLedgerEntry::TYPE_REFUND => 211, CreditLedgerEntry::TYPE_MERCHANT_REFUNDED => -211], $this->lines($correction));
    }
}
