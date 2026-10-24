<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\CreditLedgerEntry;
use App\Models\MerchantPayout;
use App\Models\StripeEvent;
use App\Models\User;
use App\Services\Corrections\CorrectionDecisions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Refunding a cleared redemption to the employee (ADR 0028): full or
 * partial, by menu items (tax from the redemption's own snapshot) or by
 * amount. Paid through Stripe: that part of the merchant's transfer is
 * reversed, and the ledger lines are written when Stripe's webhook
 * confirms. Simulated: refunded at once, netted from what the merchant is
 * owed. Never more than was paid, across every refund.
 */
class RedemptionRefundTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->withoutSeededCorrections();
    }

    private function decisions(): CorrectionDecisions
    {
        return app(CorrectionDecisions::class);
    }

    public function test_a_simulated_partial_refund_of_a_typed_in_amount_completes_at_once(): void
    {
        $redemption = $this->cleared(1250);
        $employeeBefore = $redemption->employee->balanceCents();
        $merchantBefore = $this->farragut()->payableCents();

        $correction = $this->decisions()->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Charged $5 too much.', amountCents: 500);

        $this->assertSame(Correction::STATUS_COMPLETED, $correction->status);
        $this->assertNull($correction->open_key);
        $this->assertNotNull($correction->completed_at);
        $this->assertSame(500, $correction->amount_cents);
        $this->assertNull($correction->tax_cents, 'A typed-in amount already includes any tax; none is split out');
        $this->assertSame([CorrectionAction::KIND_SIMULATED], $correction->actions->pluck('kind')->all());

        $lines = CreditLedgerEntry::where('correction_id', $correction->id)->orderBy('id')->get();
        $this->assertSame([[CreditLedgerEntry::TYPE_REFUND, 500], [CreditLedgerEntry::TYPE_MERCHANT_REFUNDED, -500]], $lines->map(fn ($l) => [$l->type, $l->amount_cents])->all());
        $this->assertSame([$redemption->id, $redemption->id], $lines->pluck('redemption_id')->all());
        $this->assertSame($employeeBefore + 500, $redemption->employee->balanceCents());
        $this->assertSame($merchantBefore - 500, $this->farragut()->payableCents(), 'Deducted from what the merchant is owed');
        $this->assertLedgerInvariant();
    }

    public function test_never_more_than_was_paid_across_every_refund(): void
    {
        $redemption = $this->cleared(1250);
        $this->decisions()->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'First part.', amountCents: 500);

        $second = $this->refundRequest($redemption);
        try {
            $this->decisions()->approveRedemptionRefund($second, $this->admin(), 'Too much.', amountCents: 800);
            $this->fail('An over-refund was approved.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Only $7.50 of this redemption can still be refunded.', $e->getMessage());
        }
        $this->assertSame(Correction::STATUS_REQUESTED, $second->fresh()->status, 'A refused approval leaves the request undecided');
        $this->assertSame(0, CreditLedgerEntry::where('correction_id', $second->id)->count());

        $this->decisions()->approveRedemptionRefund($second, $this->admin(), 'The rest.', amountCents: 750);
        $this->assertFalse($redemption->fresh()->mayAskForRefund(), 'Fully refunded: nothing left to ask about');
        $this->assertSame(1250, (int) CreditLedgerEntry::where('redemption_id', $redemption->id)->where('type', CreditLedgerEntry::TYPE_REFUND)->sum('amount_cents'));
        $this->assertLedgerInvariant();
    }

    public function test_a_menu_refund_takes_its_tax_from_the_snapshot_and_rounding_never_refunds_extra_tax(): void
    {
        // Two drip coffees at $3.75, prepared food 10%: tax 75¢ on $7.50 (37.5¢ each would round to 38¢ twice).
        $redemption = $this->clearedOrder(['Drip coffee' => 2, 'Croissant' => 1]);
        $coffee = $redemption->items->firstWhere('name', 'Drip coffee');
        $croissant = $redemption->items->firstWhere('name', 'Croissant');
        $this->assertSame(118, $redemption->tax_cents);

        // Today's config changing must not change a refund of an old order.
        config(['tax.categories.prepared_food.rates' => [['rate_bps' => 2500, 'effective_from' => '2000-01-01', 'source' => 'test', 'verified_on' => '2026-10-24']]]);

        $first = $this->decisions()->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'One coffee was never made.', [$coffee->id => 1]);
        $this->assertSame([375, 38, 413], [$first->subtotal_cents, $first->tax_cents, $first->amount_cents]);
        $this->assertSame(1000, $first->tax_lines[0]['rate_bps']);
        $this->assertSame([[$coffee->id, 1, 375]], $first->items->map(fn ($i) => [$i->redemption_item_id, $i->quantity, $i->amount_cents])->all());

        $second = $this->decisions()->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Whole order.', [$coffee->id => 1, $croissant->id => 1]);
        $this->assertSame([800, 80, 880], [$second->subtotal_cents, $second->tax_cents, $second->amount_cents]);

        $this->assertSame($redemption->tax_cents, $first->tax_cents + $second->tax_cents, 'Exactly the tax charged, no more');
        $this->assertSame($redemption->amount_cents, $first->amount_cents + $second->amount_cents);
        $this->assertLedgerInvariant();
    }

    public function test_items_can_only_be_refunded_as_many_times_as_they_were_bought(): void
    {
        $redemption = $this->clearedOrder(['Latte' => 1]);
        $latte = $redemption->items->sole();
        $this->decisions()->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Spilled.', [$latte->id => 1]);

        $this->expectExceptionMessage('Only 0 of Latte can still be refunded.');
        $this->decisions()->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Again?', [$latte->id => 1]);
    }

    public function test_a_stripe_paid_refund_reverses_the_transfer_and_the_webhook_writes_the_ledger_once(): void
    {
        $this->fakeStripe();
        $this->stripePurchase();
        $this->onboard();
        $redemption = $this->cleared(1250);
        $payout = MerchantPayout::where('redemption_id', $redemption->id)->sole();
        $this->assertSame(MerchantPayout::STATUS_SENT, $payout->status);
        $employeeBefore = $redemption->employee->balanceCents();
        $merchantBefore = $this->farragut()->payableCents();
        $ledgerBefore = CreditLedgerEntry::count();

        $correction = $this->decisions()->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Wrong size.', amountCents: 450);

        [$call] = $this->stripe->callsOf('reversal');
        $this->assertSame([$payout->stripe_transfer_id, 450], [$call['transfer'], $call['amount']]);
        $action = $correction->actions->sole();
        $this->assertSame($action->idempotency_key, $call['metadata']['lg_correction_key']);
        $this->assertSame(['testing', $correction->id], [$call['metadata']['lg_env'], (int) $call['metadata']['lg_correction_id']]);
        $this->assertSame(Correction::STATUS_PROCESSING, $correction->status);
        $this->assertStringStartsWith('trr_test_', $action->stripe_id);
        $this->assertSame($ledgerBefore, CreditLedgerEntry::count(), 'Nothing is written until Stripe\'s webhook confirms');

        $event = $this->reversedEvent($payout);
        $this->postWebhook($event)->assertOk()->assertJsonPath('outcome', 'refund recorded: '.$action->stripe_id);

        $correction->refresh();
        $this->assertSame(Correction::STATUS_COMPLETED, $correction->status);
        $lines = CreditLedgerEntry::where('correction_id', $correction->id)->orderBy('id')->get();
        $this->assertSame([[CreditLedgerEntry::TYPE_REFUND, 450], [CreditLedgerEntry::TYPE_MERCHANT_REFUNDED, -450], [CreditLedgerEntry::TYPE_REFUND_REVERSAL, 450]],
            $lines->map(fn ($l) => [$l->type, $l->amount_cents])->all());
        $this->assertSame($payout->id, $lines->last()->merchant_payout_id);
        $this->assertSame($employeeBefore + 450, $redemption->employee->balanceCents());
        $this->assertSame($merchantBefore, $this->farragut()->payableCents(), 'Earning taken back and the money came back: the merchant nets to zero');
        $this->assertSame(MerchantPayout::STATUS_SENT, $payout->fresh()->status, 'A refund reversal is not "reversed, owed again"');

        // Stripe sending it again (same event, or a new event about the same reversal) changes nothing.
        $this->postWebhook($event)->assertOk()->assertJsonPath('outcome', 'duplicate: already handled, nothing changed');
        $this->postWebhook($this->reversedEvent($payout))->assertOk()->assertJsonPath('outcome', 'refund already recorded: '.$action->stripe_id);
        $this->assertSame(3, CreditLedgerEntry::where('correction_id', $correction->id)->count());
        $this->assertSame(2, StripeEvent::count());
        $this->assertLedgerInvariant();
    }

    public function test_a_full_stripe_refund_reverses_all_of_the_transfer_and_nothing_is_owed_again(): void
    {
        $this->fakeStripe();
        $this->stripePurchase();
        $this->onboard();
        $redemption = $this->cleared(1250);
        $payout = MerchantPayout::where('redemption_id', $redemption->id)->sole();

        $this->decisions()->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Order cancelled.', amountCents: 1250);
        $event = $this->reversedEvent($payout);
        $this->assertTrue($event['data']['object']['reversed']);
        $this->postWebhook($event)->assertOk();

        $this->assertSame(MerchantPayout::STATUS_SENT, $payout->fresh()->status);
        $this->assertSame(0, CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_PAYOUT_REVERSED)->count());
        $this->assertSame(0, $this->farragut()->owedViaStripeCents());
        $this->assertLedgerInvariant();
    }

    public function test_a_reversal_made_outside_local_goods_still_takes_the_old_path(): void
    {
        $this->fakeStripe();
        $this->stripePurchase();
        $this->onboard();
        $redemption = $this->cleared(1250);
        $payout = MerchantPayout::where('redemption_id', $redemption->id)->sole();

        // A refund of $4.50, then someone reverses the rest in the Stripe dashboard.
        $this->decisions()->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Wrong size.', amountCents: 450);
        $this->stripe->reverseTransfer($payout->stripe_transfer_id, ['amount' => 800, 'metadata' => []], 'dashboard-by-hand');

        $this->postWebhook($this->reversedEvent($payout))->assertOk()
            ->assertJsonPath('outcome', fn ($o) => str_contains($o, 'refund recorded') && str_contains($o, 'owed again'));
        $this->assertSame(MerchantPayout::STATUS_REVERSED, $payout->fresh()->status);
    }

    public function test_a_refund_waits_until_the_stripe_transfer_has_gone_out(): void
    {
        $this->fakeStripe();
        $this->stripePurchase();
        $redemption = $this->cleared(1250); // merchant not onboarded: owed, not sent
        $this->assertSame(MerchantPayout::STATUS_OWED, MerchantPayout::where('redemption_id', $redemption->id)->value('status'));
        $request = $this->refundRequest($redemption);

        foreach ([MerchantPayout::STATUS_OWED => CorrectionDecisions::NOT_SENT_YET, MerchantPayout::STATUS_FAILED => CorrectionDecisions::NOT_SENT_YET,
            MerchantPayout::STATUS_REVERSED => CorrectionDecisions::REVERSED_IN_DASHBOARD] as $status => $message) {
            MerchantPayout::where('redemption_id', $redemption->id)->update(['status' => $status]);
            try {
                $this->decisions()->approveRedemptionRefund($request, $this->admin(), 'Refund it.', amountCents: 100);
                $this->fail('Refunded while the transfer was '.$status);
            } catch (InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
            $this->assertSame(Correction::STATUS_REQUESTED, $request->fresh()->status);
        }

        $this->assertSame([], $this->stripe->callsOf('reversal'));
        $this->assertSame(0, CreditLedgerEntry::whereNotNull('correction_id')->count());
    }

    public function test_only_a_platform_admin_decides_and_always_with_a_reason(): void
    {
        $request = $this->refundRequest($this->cleared(1250));

        foreach ([Role::BidAdmin, Role::EmployerAdmin, Role::Judge] as $role) {
            try {
                $this->decisions()->approveRedemptionRefund($request, User::factory()->role($role)->create(), 'Refund.', amountCents: 100);
                $this->fail($role->value.' decided a correction.');
            } catch (LogicException) {
            }
        }

        $this->expectExceptionMessage('Write a reason. The person who asked will see it.');
        $this->decisions()->reject($request, $this->admin(), '   ');
    }

    public function test_a_rejection_closes_the_request_with_its_reason_and_moves_nothing(): void
    {
        $request = $this->refundRequest($this->cleared(1250));
        $ledger = CreditLedgerEntry::count();

        $rejected = $this->decisions()->reject($request, $this->admin(), 'The receipt shows the right amount.');

        $this->assertSame([Correction::STATUS_REJECTED, null, 'The receipt shows the right amount.'], [$rejected->status, $rejected->open_key, $rejected->decision_reason]);
        $this->assertSame($ledger, CreditLedgerEntry::count());
        $this->expectExceptionMessage('This request was already decided.');
        $this->decisions()->approveRedemptionRefund($request, $this->admin(), 'Changed my mind.', amountCents: 100);
    }

    public function test_two_admins_approving_the_same_request_refund_it_once(): void
    {
        $redemption = $this->cleared(1250);
        $request = $this->refundRequest($redemption);
        $stale = Correction::findOrFail($request->id);

        $this->decisions()->approveRedemptionRefund($request, $this->admin(), 'Refund.', amountCents: 1250);
        try {
            $this->decisions()->approveRedemptionRefund($stale, $this->admin(), 'Refund.', amountCents: 1250);
            $this->fail('Approved twice.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('This request was already decided.', $e->getMessage());
        }

        $this->assertSame(1250, (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_REFUND)->sum('amount_cents'));
    }
}
