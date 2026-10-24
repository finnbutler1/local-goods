<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CreditLedgerEntry;
use App\Models\MerchantPayout;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\Payments\MerchantPayouts;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * "I wasn't paid" (ADR 0028): the existing payout path sends the merchant's
 * owed Stripe payout row, for exactly the amount the ledger shows, through
 * the TransferGate. No new kind of transfer. A sent or simulated payout has
 * nothing owed; one reversed in the Stripe dashboard is refused.
 */
class MerchantAdjustmentTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use UsesFakeStripe;

    private MerchantPayout $payout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->stripePurchase();
        $this->payout = MerchantPayout::where('redemption_id', $this->cleared(1250)->id)->sole();
    }

    private function request(): Correction
    {
        return Correction::create([
            'type' => Correction::TYPE_MERCHANT_ADJUSTMENT, 'status' => Correction::STATUS_REQUESTED,
            'open_key' => Correction::openKeyFor(Correction::TYPE_MERCHANT_ADJUSTMENT, $this->payout->id),
            'requested_by_role' => Role::Merchant->value, 'reason_category' => 'not_received',
            'merchant_payout_id' => $this->payout->id, 'merchant_id' => $this->payout->merchant_id, 'redemption_id' => $this->payout->redemption_id,
        ]);
    }

    private function approve(?Correction $request = null): Correction
    {
        return app(CorrectionDecisions::class)->approveMerchantAdjustment($request ?? $this->request(), $this->admin(), 'The transfer never went out.');
    }

    public function test_an_owed_payout_is_sent_by_the_existing_path_once_the_merchant_can_receive_it(): void
    {
        $this->assertSame(MerchantPayout::STATUS_OWED, $this->payout->status);

        $correction = $this->approve();

        $this->assertSame(Correction::STATUS_PROCESSING, $correction->status);
        $this->assertSame(1250, $correction->amount_cents);
        $this->assertStringContainsString('finish Stripe onboarding', $correction->failure_reason);
        $this->assertSame([], $this->stripe->callsOf('transfer'));

        $this->onboard();
        $correction = app(CorrectionDecisions::class)->retry($correction, $this->admin());

        [$transfer] = $this->stripe->callsOf('transfer');
        $this->assertSame(1250, $transfer['amount'], 'Exactly the shortfall the ledger shows');
        $this->assertSame(MerchantPayout::STATUS_SENT, $this->payout->fresh()->status);
        $this->assertSame(Correction::STATUS_COMPLETED, $correction->status);
        $line = CreditLedgerEntry::where('merchant_payout_id', $this->payout->id)->where('type', CreditLedgerEntry::TYPE_PAYOUT)->sole();
        $this->assertSame([-1250, $correction->id], [$line->amount_cents, $line->correction_id]);
        $this->assertLedgerInvariant();
    }

    public function test_a_failed_transfer_is_retried_and_a_refusal_shows_on_the_correction(): void
    {
        $this->onboard();
        $this->stripe->transferFailures[] = ['Stripe says the platform balance is too low for this transfer yet.'];
        app(MerchantPayouts::class)->send($this->farragut());
        $this->assertSame(MerchantPayout::STATUS_FAILED, $this->payout->fresh()->status);

        $this->stripe->transferFailures[] = ['Stripe says the platform balance is too low for this transfer yet.'];
        $correction = $this->approve();
        $this->assertSame(Correction::STATUS_FAILED, $correction->status);
        $this->assertSame('Stripe says the platform balance is too low for this transfer yet.', $correction->failure_reason);

        $correction = app(CorrectionDecisions::class)->retry($correction, $this->admin());

        $this->assertSame(Correction::STATUS_COMPLETED, $correction->status);
        $this->assertCount(3, $this->stripe->callsOf('transfer'), 'Two refusals and one transfer');
        $this->assertCount(1, $this->stripe->transfers);
        $this->assertLedgerInvariant();
    }

    public function test_the_webhook_completes_an_adjustment_whose_row_another_path_sent(): void
    {
        $correction = $this->approve();
        $this->onboard();
        app(MerchantPayouts::class)->send($this->farragut()); // e.g. Reconciliation → Retry owed transfers

        $this->assertSame(Correction::STATUS_PROCESSING, $correction->fresh()->status);
        $this->postWebhook($this->stripeEvent('transfer.created', $this->stripe->transfer($this->payout->fresh()->stripe_transfer_id)))->assertOk();

        $this->assertSame(Correction::STATUS_COMPLETED, $correction->fresh()->status);
        $this->assertSame($correction->id, CreditLedgerEntry::where('merchant_payout_id', $this->payout->id)->value('correction_id'));
    }

    public function test_nothing_is_sent_for_a_payout_already_transferred_or_reversed_in_the_dashboard(): void
    {
        $this->onboard();
        app(MerchantPayouts::class)->send($this->farragut());
        $request = $this->request();

        try {
            $this->approve($request);
            $this->fail('Sent an adjustment for a payout already transferred.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('This payout was transferred', $e->getMessage());
        }

        $this->payout->update(['status' => MerchantPayout::STATUS_REVERSED]);
        try {
            $this->approve($request);
            $this->fail('Sent an adjustment for a payout reversed in the dashboard.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(CorrectionDecisions::REVERSED_IN_DASHBOARD, $e->getMessage());
        }

        $this->assertSame(Correction::STATUS_REQUESTED, $request->fresh()->status);
        $this->assertCount(1, $this->stripe->callsOf('transfer'));
    }

    public function test_a_closed_adjustment_writes_nothing_and_the_row_is_still_owed(): void
    {
        $this->onboard();
        $this->stripe->transferFailures[] = ['Stripe refused the request (example).'];
        $correction = $this->approve();

        app(CorrectionDecisions::class)->close($correction, $this->admin(), 'Paid by hand outside Local Goods.');

        $this->assertSame(Correction::STATUS_CLOSED, $correction->fresh()->status);
        $this->assertSame(MerchantPayout::STATUS_FAILED, $this->payout->fresh()->status, 'The payout row itself is untouched');
        $this->assertSame(0, CreditLedgerEntry::whereNotNull('correction_id')->count());
    }
}
