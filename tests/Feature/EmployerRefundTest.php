<?php

namespace Tests\Feature;

use App\Enums\Occasion;
use App\Enums\Role;
use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Models\MerchantPayout;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\CreditLedger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Refunding unspent credits to the employer (ADR 0028): only credits not
 * yet given to employees, held at once so they can't be given out while the
 * refund goes through, refunded newest payment first, each payment at most
 * what is left of it, credits only (never the platform fee). Stripe
 * payments get a Stripe refund confirmed by the webhook; simulated
 * purchases are returned at once, simulated.
 */
class EmployerRefundTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    private Employer $employer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $this->withoutSeededCorrections();
        $this->employer = Employer::firstOrFail();
    }

    private function request(): Correction
    {
        return Correction::create([
            'type' => Correction::TYPE_EMPLOYER_REFUND, 'status' => Correction::STATUS_REQUESTED,
            'open_key' => Correction::openKeyFor(Correction::TYPE_EMPLOYER_REFUND, $this->employer->id),
            'requested_by_role' => Role::EmployerAdmin->value, 'reason_category' => 'bought_too_much', 'employer_id' => $this->employer->id,
        ]);
    }

    private function approve(int $cents): Correction
    {
        return app(CorrectionDecisions::class)->approveEmployerRefund($this->request(), $this->admin(), 'Unspent credits back to the card.', $cents);
    }

    public function test_a_simulated_purchase_is_returned_at_once_from_the_newest_payment(): void
    {
        $balance = $this->employer->balanceCents();
        $newest = CreditPurchase::where('employer_id', $this->employer->id)->orderByDesc('purchased_at')->orderByDesc('id')->firstOrFail();

        $correction = $this->approve(10000);

        $this->assertSame(Correction::STATUS_COMPLETED, $correction->status);
        $action = $correction->actions->sole();
        $this->assertSame([CorrectionAction::KIND_SIMULATED, $newest->id, 10000], [$action->kind, $action->credit_purchase_id, $action->amount_cents]);
        $this->assertSame($balance - 10000, $this->employer->balanceCents());
        $this->assertSame([[CreditLedgerEntry::TYPE_EMPLOYER_REFUND_HOLD, -10000], [CreditLedgerEntry::TYPE_EMPLOYER_REFUNDED, 0]],
            CreditLedgerEntry::where('correction_id', $correction->id)->orderBy('id')->get()->map(fn ($l) => [$l->type, $l->amount_cents])->all());
        $this->assertSame(10000, $newest->refundedCents());
        $this->assertLedgerInvariant();
    }

    public function test_only_unspent_credits_can_be_refunded(): void
    {
        $balance = $this->employer->balanceCents();
        $request = $this->request();

        try {
            app(CorrectionDecisions::class)->approveEmployerRefund($request, $this->admin(), 'All of it.', $balance + 1);
            $this->fail('Refunded credits already given to employees.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('Only ', $e->getMessage());
        }

        $this->assertSame(Correction::STATUS_REQUESTED, $request->fresh()->status);
        $this->assertSame(0, CreditLedgerEntry::whereNotNull('correction_id')->count());
    }

    public function test_a_stripe_payment_gets_a_stripe_refund_and_the_webhook_confirms_it_once(): void
    {
        $this->fakeStripe();
        $purchase = $this->stripePurchase(30000, 'ch_test_A');

        $correction = $this->approve(20000);

        [$call] = $this->stripe->callsOf('refund');
        $this->assertSame(['pi_ch_test_A', 20000, 'requested_by_customer'], [$call['payment_intent'], $call['amount'], $call['reason']]);
        $action = $correction->actions->sole();
        $this->assertSame($action->idempotency_key, $call['metadata']['lg_correction_key']);
        $this->assertSame('testing', $call['metadata']['lg_env']);
        $this->assertArrayNotHasKey('lg_purchase_token', $call['metadata']);
        $this->assertSame(Correction::STATUS_PROCESSING, $correction->status);
        $this->assertSame([CreditLedgerEntry::TYPE_EMPLOYER_REFUND_HOLD], CreditLedgerEntry::where('correction_id', $correction->id)->pluck('type')->all(),
            'Held at once; the refund line waits for Stripe\'s webhook');

        $created = $this->stripeEvent('refund.created', $this->stripe->refunds[0]);
        $this->postWebhook($created)->assertOk()->assertJsonPath('outcome', 'card refund recorded: '.$action->stripe_id);

        $this->assertSame(Correction::STATUS_COMPLETED, $correction->fresh()->status);
        $done = CreditLedgerEntry::where('correction_id', $correction->id)->where('type', CreditLedgerEntry::TYPE_EMPLOYER_REFUNDED)->sole();
        $this->assertSame([0, $purchase->id], [$done->amount_cents, $done->credit_purchase_id]);
        $this->assertStringContainsString($action->stripe_id, $done->description);

        // refund.updated follows with the same status; a replay of either changes nothing.
        $this->postWebhook($this->stripeEvent('refund.updated', $this->stripe->refunds[0]))->assertOk()->assertJsonPath('outcome', 'card refund already recorded: '.$action->stripe_id);
        $this->postWebhook($created)->assertOk()->assertJsonPath('outcome', 'duplicate: already handled, nothing changed');
        $this->assertSame(2, CreditLedgerEntry::where('correction_id', $correction->id)->count());
        $this->assertSame(10000, $purchase->fresh()->refundableCents());
        $this->assertLedgerInvariant();
    }

    public function test_the_refund_splits_newest_first_and_never_takes_what_transfers_claimed(): void
    {
        $this->fakeStripe();
        $older = $this->stripePurchase(20000, 'ch_test_A');
        $this->travel(1)->minutes();
        $newer = $this->stripePurchase(10000, 'ch_test_B');
        $this->onboard();
        $this->cleared(5000); // claims $50 of the older payment (oldest first)
        $this->assertSame(15000, $older->fresh()->refundableCents());

        $correction = $this->approve(26000);

        $parts = $correction->actions->map(fn ($a) => [$a->credit_purchase_id, $a->kind, $a->amount_cents])->all();
        $this->assertSame([$newer->id, CorrectionAction::KIND_CARD_REFUND, 10000], $parts[0]);
        $this->assertSame([$older->id, CorrectionAction::KIND_CARD_REFUND, 15000], $parts[1]);
        $this->assertSame(CorrectionAction::KIND_SIMULATED, $parts[2][1], 'The rest comes from a simulated purchase');
        $this->assertSame(1000, $parts[2][2]);
        $this->assertSame([10000, 15000], array_column($this->stripe->callsOf('refund'), 'amount'));

        foreach ($this->stripe->refunds as $refund) {
            $this->postWebhook($this->stripeEvent('refund.created', $refund))->assertOk();
        }
        $this->assertSame(Correction::STATUS_COMPLETED, $correction->fresh()->status);
        $this->assertSame(0, $older->fresh()->refundableCents());
        $this->assertLedgerInvariant();
    }

    public function test_the_platform_fee_is_never_refunded(): void
    {
        config(['payments.platform_fee_percent' => '2.5']);
        $this->fakeStripe();
        $purchase = $this->stripePurchase(20000, 'ch_test_F');
        $this->assertSame(500, $purchase->platform_fee_cents);
        $this->assertSame(20000, $purchase->refundableCents());

        $this->approve(20000);

        $this->assertSame(20000, $this->stripe->callsOf('refund')[0]['amount']);
    }

    public function test_held_credits_cannot_be_given_to_employees(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(30000, 'ch_test_A');
        $balance = $this->employer->balanceCents();
        $this->approve(30000);

        $this->assertSame($balance - 30000, $this->employer->balanceCents());
        $this->expectException(InvalidArgumentException::class);
        app(CreditLedger::class)->allocateMany($this->employer, [$this->shopper()], $balance - 30000 + 100, Occasion::Recognition);
    }

    public function test_a_refused_refund_fails_and_close_gives_the_held_credits_back(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(30000, 'ch_test_A');
        $balance = $this->employer->balanceCents();
        $this->stripe->refundFailures[] = ['Stripe refused the request (charge_already_refunded).', true];

        $correction = $this->approve(20000);

        $this->assertSame(Correction::STATUS_FAILED, $correction->status);
        $this->assertSame('Stripe refused the request (charge_already_refunded).', $correction->failure_reason);
        $this->assertSame($balance - 20000, $this->employer->balanceCents(), 'Still held while it can be retried');

        $closed = app(CorrectionDecisions::class)->close($correction, $this->admin(), 'Already refunded by hand in Stripe.');

        $this->assertSame([Correction::STATUS_CLOSED, null], [$closed->status, $closed->open_key]);
        $this->assertSame($balance, $this->employer->balanceCents());
        $this->assertSame(20000, (int) CreditLedgerEntry::where('correction_id', $correction->id)->where('type', CreditLedgerEntry::TYPE_EMPLOYER_REFUND_RELEASED)->sum('amount_cents'));
        $this->assertLedgerInvariant();
    }

    public function test_a_refund_stripe_reports_failed_fails_the_correction_and_keeps_the_hold(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(30000, 'ch_test_A');
        $correction = $this->approve(20000);

        $failed = ['status' => 'failed', 'failure_reason' => 'expired_or_canceled_card'] + $this->stripe->refunds[0];
        $this->postWebhook($this->stripeEvent('refund.failed', $failed))->assertOk()->assertJsonPath('outcome', fn ($o) => str_starts_with($o, 'card refund failed'));

        $correction->refresh();
        $this->assertSame(Correction::STATUS_FAILED, $correction->status);
        $this->assertStringContainsString('expired or canceled card', $correction->failure_reason);
        $this->assertSame(0, CreditLedgerEntry::where('correction_id', $correction->id)->where('type', CreditLedgerEntry::TYPE_EMPLOYER_REFUNDED)->count());
        $this->assertLedgerInvariant();
    }

    public function test_refunded_money_never_funds_a_later_stripe_transfer(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(10000, 'ch_test_A');
        $this->approve(10000);
        $this->onboard();

        $redemption = $this->cleared(2500);

        $this->assertNull(MerchantPayout::where('redemption_id', $redemption->id)->first(), 'No Stripe money left to fund it, so it stays simulated');
        $this->assertSame([], $this->stripe->callsOf('transfer'));
    }
}
