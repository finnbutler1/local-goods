<?php

namespace Tests\Feature;

use App\Enums\DenialReason;
use App\Enums\Role;
use App\Http\Controllers\Merchant\PayCodeController;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\MerchantPayout;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
use App\Services\PayCodes;
use App\Services\Payments\MerchantPayouts;
use App\Services\RedemptionReviewService;
use App\Services\RedemptionService;
use App\Support\MerchantFee;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SetsMerchantFee;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The merchant fee on a sale (ADR 0029): snapshotted when the redemption is
 * made, charged when it clears as a merchant → platform pair of ledger
 * lines, and held back from the Stripe transfer. Simulated mode nets the
 * same way. The ledger invariant holds throughout.
 */
class MerchantFeeSaleTest extends TestCase
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

    private function feeLines(Redemption $redemption): array
    {
        return CreditLedgerEntry::where('redemption_id', $redemption->id)
            ->whereIn('type', [CreditLedgerEntry::TYPE_MERCHANT_FEE, CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE])
            ->orderBy('id')->get()->map(fn ($e) => [$e->type, $e->amount_cents, $e->merchant_id !== null ? 'merchant' : ($e->platform ? 'platform' : '?')])->all();
    }

    private function platformCents(): int
    {
        return (int) CreditLedgerEntry::where('platform', true)->sum('amount_cents');
    }

    public function test_the_fee_is_snapshotted_and_later_changes_never_touch_it(): void
    {
        $this->setMerchantFee(250);
        $redemption = $this->cleared(633);
        $this->assertSame([250, 0, 16], [$redemption->merchant_fee_bps, $redemption->merchant_fee_fixed_cents, $redemption->merchant_fee_cents]);

        $this->setMerchantFee(1000, 100);
        $this->setMerchantFee(0);

        $redemption->refresh();
        $this->assertSame([250, 0, 16], [$redemption->merchant_fee_bps, $redemption->merchant_fee_fixed_cents, $redemption->merchant_fee_cents]);
        $this->assertSame(0, $this->cleared(633)->merchant_fee_cents, 'A new redemption takes the new setting');
    }

    public function test_with_no_fee_nothing_changes(): void
    {
        $platformBefore = $this->platformCents();
        $redemption = $this->cleared(633);

        $this->assertSame(0, $redemption->merchant_fee_cents);
        $this->assertSame([], $this->feeLines($redemption));
        $this->assertSame(1, CreditLedgerEntry::where('redemption_id', $redemption->id)->where('merchant_id', '!=', null)->count(), 'Only the merchant_earned line');
        $this->assertSame($platformBefore, $this->platformCents());
        $this->assertLedgerInvariant();
    }

    public function test_simulated_mode_nets_the_fee_from_what_the_merchant_is_owed(): void
    {
        $this->setMerchantFee(250);
        $merchantBefore = $this->farragut()->payableCents();
        $platformBefore = $this->platformCents();

        $redemption = $this->cleared(633);

        $this->assertSame([
            [CreditLedgerEntry::TYPE_MERCHANT_FEE, -16, 'merchant'],
            [CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE, 16, 'platform'],
        ], $this->feeLines($redemption));
        $this->assertSame($merchantBefore + 617, $this->farragut()->payableCents());
        $this->assertSame($platformBefore + 16, $this->platformCents());
        $this->assertSame(0, MerchantPayout::where('redemption_id', $redemption->id)->count(), 'Simulated: no Stripe row');

        // The merchant's simulated payout is the net.
        app(CreditLedger::class)->payout($this->farragut(), $this->farragut()->payableCents(), MerchantPayout::STATUS_SIMULATED);
        $this->assertSame(0, $this->farragut()->payableCents());
        $this->assertLedgerInvariant();
    }

    public function test_the_stripe_transfer_is_the_sale_less_the_fee(): void
    {
        $this->fakeStripe();
        $purchase = $this->stripePurchase(10000);
        $this->onboard();
        $this->setMerchantFee(250, 10);
        $platformBefore = $this->platformCents();

        $redemption = $this->cleared(633); // 15.825 → 16, + 10 = 26

        $payout = MerchantPayout::where('redemption_id', $redemption->id)->sole();
        $this->assertSame([MerchantPayout::STATUS_SENT, 607, 26], [$payout->status, $payout->amount_cents, $payout->fee_cents]);
        [$call] = $this->stripe->callsOf('transfer');
        $this->assertSame(607, $call['amount']);
        $this->assertSame('26', $call['metadata']['lg_merchant_fee_cents']);
        $this->assertSame((string) $redemption->id, $call['metadata']['lg_redemption_id']);

        // The purchase is claimed for the whole sale: the fee stays the platform's.
        $this->assertSame(10000 - 633, $purchase->fresh()->unclaimedCents());
        $this->assertSame(0, $this->farragut()->payableCents() - $this->farragut()->owedSimulatedCents());
        $this->assertSame($platformBefore + 26, $this->platformCents());
        $this->assertLedgerInvariant();
    }

    public function test_with_no_fee_the_transfer_is_exactly_as_before(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(10000);
        $this->onboard();

        $redemption = $this->cleared(633);

        [$call] = $this->stripe->callsOf('transfer');
        $this->assertSame(633, $call['amount']);
        $this->assertArrayNotHasKey('lg_merchant_fee_cents', $call['metadata']);
        $this->assertSame([633, 0], [$redemption->stripePayout->amount_cents, $redemption->stripePayout->fee_cents]);
    }

    public function test_a_held_redemption_is_charged_its_own_snapshot_only_when_approved(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(10000);
        $this->onboard();
        $this->setMerchantFee(250);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(88, 'hold'));
        $employee = $this->shopper();
        $held = app(RedemptionService::class)->redeem($employee, $this->farragut(), 633);

        $this->assertSame(16, $held->merchant_fee_cents);
        $this->assertSame([], $this->feeLines($held), 'Nothing is charged while held');
        $this->assertSame(0, MerchantPayout::where('redemption_id', $held->id)->count());

        $this->setMerchantFee(1000); // a change while it is held doesn't touch it
        app(RedemptionReviewService::class)->approve($held, $this->admin(), 'Checked with the shop.');

        $this->assertSame([[CreditLedgerEntry::TYPE_MERCHANT_FEE, -16, 'merchant'], [CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE, 16, 'platform']], $this->feeLines($held));
        $this->assertSame(617, MerchantPayout::where('redemption_id', $held->id)->sole()->amount_cents);
        $this->assertLedgerInvariant();
    }

    public function test_a_denied_redemption_is_never_charged(): void
    {
        $this->setMerchantFee(250);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(88, 'hold'));
        $held = app(RedemptionService::class)->redeem($this->shopper(), $this->farragut(), 633);

        app(RedemptionReviewService::class)->deny($held, $this->admin(), 'No purchase happened.', DenialReason::NoPurchase);

        $this->assertSame([], $this->feeLines($held));
        $this->assertLedgerInvariant();
    }

    public function test_a_fee_of_the_whole_sale_makes_no_transfer(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(10000);
        $this->onboard();
        $this->setMerchantFee(1000, 100);

        $redemption = $this->cleared(50);

        $this->assertSame(50, $redemption->merchant_fee_cents);
        $this->assertSame(0, MerchantPayout::where('redemption_id', $redemption->id)->count());
        $this->assertSame([], $this->stripe->callsOf('transfer'));
        $this->assertLedgerInvariant();
    }

    public function test_a_pay_code_is_charged_the_fee_the_merchant_was_shown(): void
    {
        $this->setMerchantFee(250);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(5, 'clear'));
        $payCode = app(PayCodes::class)->issue(Employee::orderBy('id')->firstOrFail(), $this->farragut(), 633);
        $this->signIn(Role::Merchant);
        $this->post(route('merchant.pay-code.lookup'), ['pay_code' => $payCode->code]);
        $shown = MerchantFee::current()->changeId;

        // The admin changes the fee between look-up and confirm: refused, nothing charged, the code stays live.
        $this->setMerchantFee(1000);
        $this->post(route('merchant.pay-code.confirm', $payCode), ['fee_change' => $shown])
            ->assertSessionHasErrors(['pay_code' => PayCodeController::FEE_CHANGED]);
        $this->assertSame(PayCode::STATUS_ACTIVE, $payCode->fresh()->status);
        $this->assertNull($payCode->fresh()->redemption_id);

        $this->post(route('merchant.pay-code.confirm', $payCode), ['fee_change' => MerchantFee::current()->changeId]);
        $redemption = $payCode->fresh()->redemption;
        $this->assertSame([1000, 63], [$redemption->merchant_fee_bps, $redemption->merchant_fee_cents]);
    }

    public function test_an_owed_row_sent_after_onboarding_is_the_net(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(10000);
        $this->setMerchantFee(250);
        $redemption = $this->cleared(633); // not onboarded yet: owed

        $this->assertSame(MerchantPayout::STATUS_OWED, $redemption->stripePayout->status);
        $this->assertSame(617, $this->farragut()->owedViaStripeCents());
        $this->onboard();
        app(MerchantPayouts::class)->send($this->farragut());

        [$call] = $this->stripe->callsOf('transfer');
        $this->assertSame(617, $call['amount']);
        $this->assertLedgerInvariant();
    }
}
