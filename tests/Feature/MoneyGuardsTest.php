<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Models\RedemptionDecision;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
use App\Services\Payments\StripeFunding;
use App\Services\Payments\StripeOnboarding;
use App\Services\Payments\TransferGate;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The refusals in the money code that no other test reached: each guard
 * says no and leaves the ledger exactly as it was. Tests only; no money
 * logic changed.
 */
class MoneyGuardsTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private Employee $employee;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->employee = Employee::orderBy('id')->firstOrFail();
        $this->merchant = Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail();
    }

    private function ledger(): CreditLedger
    {
        return app(CreditLedger::class);
    }

    private function redeem(int $cents, string $decision): Redemption
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict($decision === 'hold' ? 90 : 10, $decision));

        return app(RedemptionService::class)->redeem($this->employee->fresh(), $this->merchant, $cents);
    }

    private function stripePurchase(int $cents = 50000): CreditPurchase
    {
        $purchase = $this->ledger()->requestPurchase(Employer::firstOrFail(), $cents, 'MPG-GUARD-'.$cents);
        $this->ledger()->markPurchasePaidByStripe($purchase, 'pi_guard', 'ch_guard', $cents, 'usd');

        return $purchase->fresh();
    }

    private function onboard(): void
    {
        $this->stripe->accounts['acct_test_FG'] = ['id' => 'acct_test_FG'];
        app(StripeOnboarding::class)->apply($this->merchant, $this->stripe->completeOnboarding('acct_test_FG'));
    }

    // --- Redemptions -------------------------------------------------------

    public function test_a_balance_spent_while_the_ai_is_thinking_is_caught_by_the_second_check(): void
    {
        $balance = $this->employee->balanceCents();
        $this->assertGreaterThan(0, $balance);

        // Two redemptions of the whole balance at once: the second one lands
        // while the first is waiting for the AI.
        $nested = false;
        $this->app->instance(ClaudeClient::class, new FakeClaudeClient(function () use (&$nested, $balance) {
            if (! $nested) {
                $nested = true;
                app(RedemptionService::class)->redeem($this->employee->fresh(), $this->merchant, $balance);
            }

            return json_encode(['risk_score' => 10, 'decision' => 'clear', 'explanation' => 'Looks normal.']);
        }));
        $before = Redemption::count();

        try {
            app(RedemptionService::class)->redeem($this->employee->fresh(), $this->merchant, $balance);
            $this->fail('The second redemption of the same balance went through.');
        } catch (ValidationException $e) {
            $this->assertSame('That is more than the available balance.', $e->errors()['amount'][0]);
        }

        $this->assertSame($before + 1, Redemption::count(), 'Only the first redemption was recorded.');
        $this->assertSame(0, $this->employee->fresh()->balanceCents(), 'The balance never goes below zero.');
    }

    public function test_spending_exactly_the_whole_balance_is_allowed(): void
    {
        $redemption = $this->redeem($this->employee->balanceCents(), 'clear');

        $this->assertTrue($redemption->isApproved());
        $this->assertSame(0, $this->employee->fresh()->balanceCents());
    }

    // --- The transfer gate -------------------------------------------------

    public function test_the_gate_rereads_the_row_and_ignores_a_stale_copy(): void
    {
        $held = $this->redeem(4000, 'hold');
        $held->status = Redemption::STATUS_APPROVED; // in memory only

        $this->assertFalse(app(TransferGate::class)->allows($held));
    }

    public function test_the_gate_needs_the_merchant_earning_in_the_ledger(): void
    {
        $held = $this->redeem(4000, 'hold');
        // As if a bug flipped a held row to "cleared by the AI" without the ledger entry.
        Redemption::whereKey($held->id)->update(['status' => Redemption::STATUS_APPROVED, 'ai_decision' => 'clear']);
        $this->assertTrue($held->fresh()->wasClearedByAi());

        $this->assertFalse(app(TransferGate::class)->allows($held));

        // The ledger entry was the missing piece.
        $this->ledger()->creditMerchant($held->fresh());
        $this->assertTrue(app(TransferGate::class)->allows($held));
    }

    public function test_a_persons_deny_closes_the_gate_even_on_an_ai_clear(): void
    {
        $cleared = $this->redeem(437, 'clear');
        $this->assertTrue(app(TransferGate::class)->allows($cleared));

        $admin = User::factory()->role(Role::BidAdmin)->create();
        RedemptionDecision::create([
            'redemption_id' => $cleared->id, 'decision' => RedemptionDecision::DENY, 'reason' => 'Test.',
            'decided_by_user_id' => $admin->id, 'decided_by_name' => $admin->name, 'decided_by_role' => $admin->role->value,
            'ai_decision' => 'clear', 'ai_risk_score' => 10, 'ai_scored_by' => 'claude-opus-5-5', 'decided_at' => now(),
        ]);

        $this->assertFalse(app(TransferGate::class)->allows($cleared));
    }

    public function test_the_gate_refuses_a_redemption_that_does_not_exist(): void
    {
        $missing = new Redemption;
        $missing->id = 999999;

        $this->assertFalse(app(TransferGate::class)->allows($missing));
    }

    public function test_an_ai_clear_that_a_person_later_touched_no_longer_counts_as_the_ais(): void
    {
        $cleared = $this->redeem(437, 'clear');
        Redemption::whereKey($cleared->id)->update(['reviewed_at' => now()]);

        $this->assertFalse($cleared->fresh()->wasClearedByAi());
        $this->assertFalse(app(TransferGate::class)->allows($cleared));
    }

    // --- Ledger refusals ---------------------------------------------------

    public function test_a_stripe_payment_that_does_not_match_the_purchase_is_refused(): void
    {
        $purchase = $this->ledger()->requestPurchase(Employer::firstOrFail(), 25000, 'MPG-MISMATCH');
        $entries = CreditLedgerEntry::count();

        foreach ([[24999, 'usd'], [25000, 'eur']] as [$cents, $currency]) {
            try {
                $this->ledger()->markPurchasePaidByStripe($purchase, 'pi_x', 'ch_x', $cents, $currency);
                $this->fail("A {$cents} {$currency} payment was accepted for a 25000 usd purchase.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('does not match purchase MPG-MISMATCH', $e->getMessage());
            }
        }

        $this->assertSame(CreditPurchase::STATUS_PENDING, $purchase->fresh()->status);
        $this->assertSame($entries, CreditLedgerEntry::count());

        // Upper-case currency from Stripe is the same currency.
        $this->assertTrue($this->ledger()->markPurchasePaidByStripe($purchase, 'pi_x', 'ch_x', 25000, 'USD'));
    }

    public function test_a_stripe_payment_is_recorded_once(): void
    {
        $purchase = $this->ledger()->requestPurchase(Employer::firstOrFail(), 25000, 'MPG-ONCE');

        $this->assertTrue($this->ledger()->markPurchasePaidByStripe($purchase, 'pi_once', 'ch_once', 25000, 'usd'));
        $this->assertFalse($this->ledger()->markPurchasePaidByStripe($purchase, 'pi_other', 'ch_other', 25000, 'usd'));

        $this->assertSame(1, CreditLedgerEntry::where('credit_purchase_id', $purchase->id)->count());
        $this->assertSame('pi_once', $purchase->fresh()->stripe_payment_intent_id);
    }

    public function test_credits_cannot_go_to_another_employers_employee(): void
    {
        $other = Employer::create(['bid_id' => Employer::firstOrFail()->bid_id, 'name' => 'Second Test Employer', 'monthly_allowance_cents' => 10000, 'staff_count' => 10]);
        $entries = CreditLedgerEntry::count();

        $this->expectExceptionMessage('That employee does not work for this employer.');
        try {
            $this->ledger()->allocate($other, $this->employee, 1000);
        } finally {
            $this->assertSame($entries, CreditLedgerEntry::count());
        }
    }

    public function test_a_held_redemption_cannot_earn_the_merchant_anything(): void
    {
        $held = $this->redeem(4000, 'hold');

        $this->expectExceptionMessage('Only a cleared redemption earns the merchant anything.');
        try {
            $this->ledger()->creditMerchant($held);
        } finally {
            $this->assertFalse(CreditLedgerEntry::where('redemption_id', $held->id)->where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)->exists());
        }
    }

    public function test_a_transfer_and_its_reversal_are_each_recorded_once(): void
    {
        $this->stripePurchase();
        $this->onboard();
        $payout = MerchantPayout::where('redemption_id', $this->redeem(1250, 'clear')->id)->firstOrFail();
        $this->assertSame(MerchantPayout::STATUS_SENT, $payout->status);

        $this->assertFalse($this->ledger()->recordStripeTransfer($payout, 'tr_again'), 'A sent row is not sent again.');
        $this->assertTrue($this->ledger()->recordStripeReversal($payout));
        $this->assertFalse($this->ledger()->recordStripeReversal($payout), 'A reversal is recorded once.');
        $this->assertFalse($this->ledger()->recordStripeTransfer($payout, 'tr_after'), 'A reversed row is never re-sent automatically.');

        $this->assertSame(1, CreditLedgerEntry::where('merchant_payout_id', $payout->id)->where('type', CreditLedgerEntry::TYPE_PAYOUT)->count());
        $this->assertSame(1, CreditLedgerEntry::where('merchant_payout_id', $payout->id)->where('type', CreditLedgerEntry::TYPE_PAYOUT_REVERSED)->count());
    }

    public function test_an_owed_row_cannot_be_reversed(): void
    {
        $this->stripePurchase();
        // Not onboarded: the Stripe payout row stays owed.
        $payout = MerchantPayout::where('redemption_id', $this->redeem(1250, 'clear')->id)->firstOrFail();

        $this->assertSame(MerchantPayout::STATUS_OWED, $payout->status);
        $this->assertFalse($this->ledger()->recordStripeReversal($payout));
        $this->assertFalse(CreditLedgerEntry::where('merchant_payout_id', $payout->id)->exists());
    }

    public function test_money_owed_through_stripe_cannot_be_paid_out_as_simulated(): void
    {
        $this->stripePurchase();
        $this->redeem(1250, 'clear'); // owed through Stripe, merchant not onboarded
        $simulated = $this->merchant->fresh()->owedSimulatedCents();
        $this->assertSame(1250, $this->merchant->fresh()->owedViaStripeCents());

        $this->expectExceptionMessage('owed through Stripe test mode');
        $this->ledger()->payout($this->merchant->fresh(), $simulated + 1, MerchantPayout::STATUS_SIMULATED);
    }

    public function test_a_redemption_is_matched_to_a_stripe_purchase_only_once(): void
    {
        $this->stripePurchase();
        $redemption = $this->redeem(1250, 'clear');
        $this->assertSame(1, MerchantPayout::where('redemption_id', $redemption->id)->count());

        $this->assertNull(app(StripeFunding::class)->claim($redemption->fresh()));
        $this->assertSame(1, MerchantPayout::where('redemption_id', $redemption->id)->count());
    }

    public function test_a_redemption_is_never_split_across_two_stripe_purchases(): void
    {
        // Two paid purchases of $10 each; a $15 redemption fits neither alone.
        $employer = Employer::firstOrFail();
        foreach (['A', 'B'] as $suffix) {
            $p = $this->ledger()->requestPurchase($employer, 1000, 'MPG-SPLIT-'.$suffix);
            $this->ledger()->markPurchasePaidByStripe($p, 'pi_split_'.$suffix, 'ch_split_'.$suffix, 1000, 'usd');
        }

        $redemption = $this->redeem(1500, 'clear');

        $this->assertFalse(MerchantPayout::where('redemption_id', $redemption->id)->exists(), 'It stays simulated rather than being split.');
    }
}
