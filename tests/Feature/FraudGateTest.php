<?php

namespace Tests\Feature;

use App\Enums\DenialReason;
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
use App\Services\Payments\MerchantPayouts;
use App\Services\Payments\StripeFunding;
use App\Services\Payments\StripeOnboarding;
use App\Services\Payments\TransferGate;
use App\Services\RedemptionReviewService;
use App\Services\RedemptionService;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The fraud gate on transfers (ADR 0016), with Stripe and Claude faked.
 * Redemption Fraud Detection decides hold or clear; these tests check what
 * happens to the money after that. They don't measure whether the real AI
 * catches a pattern: that is the fraud eval's job (holdout_3, /eval).
 */
class FraudGateTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private Employer $employer;

    private Merchant $grind;

    private int $accounts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->employer = Employer::firstOrFail();
        $this->grind = Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail();

        // Stripe-paid credits, so a cleared redemption would really transfer.
        $ledger = app(CreditLedger::class);
        $purchase = $ledger->requestPurchase($this->employer, 300000, 'MPG-STRIPE-GATE');
        $purchase->update(['payment_token' => 'tok-gate']);
        $ledger->markPurchasePaidByStripe($purchase, 'pi_test_gate', 'ch_test_gate', 300000, 'usd');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function onboard(Merchant $merchant): void
    {
        $id = 'acct_test_'.(++$this->accounts);
        $this->stripe->accounts[$id] = ['id' => $id];
        app(StripeOnboarding::class)->apply($merchant, $this->stripe->completeOnboarding($id));
    }

    private function employee(string $name, int $topUpCents = 0): Employee
    {
        $employee = Employee::where('display_name', $name)->firstOrFail();

        if ($topUpCents > 0) {
            app(CreditLedger::class)->allocate($this->employer, $employee, $topUpCents);
        }

        return $employee->fresh();
    }

    /** A stand-in for the AI: holds round amounts, clears the rest. */
    private function aiHoldsRoundAmounts(): void
    {
        $this->app->instance(ClaudeClient::class, new FakeClaudeClient(fn (string $signals) => json_encode(
            json_decode($signals, true)['is_round_number']
                ? ['risk_score' => 85, 'decision' => 'hold', 'explanation' => 'Round amount, possible credit-to-cash.']
                : ['risk_score' => 8, 'decision' => 'clear', 'explanation' => 'Looks like a normal purchase.'],
        )));
    }

    private function redeem(Employee $employee, Merchant $merchant, int $cents): Redemption
    {
        return app(RedemptionService::class)->redeem($employee->fresh(), $merchant, $cents);
    }

    private function heldAtGrind(int $cents = 4000): Redemption
    {
        $this->aiHoldsRoundAmounts();

        return $this->redeem($this->employee('Alex Moreau', 10000), $this->grind, $cents);
    }

    private function admin(): User
    {
        return User::where('role', Role::BidAdmin)->first() ?? User::factory()->role(Role::BidAdmin)->create(['name' => 'Sam Taylor']);
    }

    private function assertNoMoneyMoved(Redemption $redemption): void
    {
        $this->assertSame(0, MerchantPayout::where('redemption_id', $redemption->id)->count(), "No payout row for #{$redemption->id}");
        $this->assertSame(0, CreditLedgerEntry::where('redemption_id', $redemption->id)->where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)->count());
        $this->assertSame([], array_filter($this->stripe->callsOf('transfer'), fn ($t) => $t['metadata']['lg_redemption_id'] === (string) $redemption->id));
        $this->assertFalse(app(TransferGate::class)->allows($redemption));
    }

    public function test_both_planted_collusion_patterns_end_up_held_with_no_transfer(): void
    {
        $stems = Merchant::where('name', DemoHistorySeeder::PATTERN_A_MERCHANT)->firstOrFail();
        $cuts = Merchant::where('name', DemoHistorySeeder::PATTERN_B_MERCHANT)->firstOrFail();
        $this->onboard($stems);
        $this->onboard($cuts);
        $this->aiHoldsRoundAmounts();

        // A: the day after the allowance, $80 then $60 at the florist within the hour, around 7:30 PM.
        $reese = $this->employee(DemoHistorySeeder::PATTERN_A_EMPLOYEE, 15000);
        Carbon::setTestNow(Carbon::parse('2026-10-02 19:25', 'America/New_York'));
        $a = [$this->redeem($reese, $stems, 8000)];
        Carbon::setTestNow(Carbon::parse('2026-10-02 20:05', 'America/New_York'));
        $a[] = $this->redeem($reese, $stems, 6000);

        // B: three people, round $60/$70 at the salon within ten minutes on a Thursday afternoon.
        $b = [];
        foreach (DemoHistorySeeder::PATTERN_B_EMPLOYEES as $i => $name) {
            Carbon::setTestNow(Carbon::parse('2026-10-08 16:50', 'America/New_York')->addMinutes(4 * $i));
            $b[] = $this->redeem($this->employee($name, 10000), $cuts, $i === 1 ? 7000 : 6000);
        }

        foreach ([...$a, ...$b] as $redemption) {
            $this->assertTrue($redemption->isUnderReview(), "#{$redemption->id} is held");
            $this->assertNoMoneyMoved($redemption);
        }

        app(MerchantPayouts::class)->send($stems);
        app(MerchantPayouts::class)->send($cuts);
        $this->assertSame([], $this->stripe->callsOf('transfer'), 'Sending again finds nothing to send');
    }

    public function test_twenty_normal_redemptions_clear_and_each_transfers_once(): void
    {
        $this->onboard($this->grind);
        $this->aiHoldsRoundAmounts();

        $redemptions = collect(DemoHistorySeeder::NAMES)->take(20)
            ->map(fn (string $name, int $i) => $this->redeem($this->employee($name, 5000), $this->grind, 437 + $i * 31));

        $this->assertTrue($redemptions->every->isApproved());
        $transfers = $this->stripe->callsOf('transfer');
        $this->assertCount(20, $transfers);
        $this->assertSame($redemptions->pluck('id')->map(fn ($id) => (string) $id)->all(), array_column(array_column($transfers, 'metadata'), 'lg_redemption_id'));
        $this->assertSame($redemptions->pluck('amount_cents')->all(), array_column($transfers, 'amount'));
        $this->assertSame(20, MerchantPayout::where('status', MerchantPayout::STATUS_SENT)->whereIn('redemption_id', $redemptions->pluck('id'))->count());

        // One key per redemption, naming it.
        $keys = MerchantPayout::whereIn('redemption_id', $redemptions->pluck('id'))->pluck('idempotency_key', 'redemption_id');
        $this->assertCount(20, $keys->unique());
        $keys->each(fn ($key, $id) => $this->assertStringContainsString("-r{$id}-", $key));

        app(MerchantPayouts::class)->send($this->grind);
        $this->assertCount(20, $this->stripe->callsOf('transfer'));
    }

    public function test_approve_sends_exactly_one_transfer_even_on_double_clicks_and_retries(): void
    {
        $this->onboard($this->grind);
        $held = $this->heldAtGrind();
        $this->assertNoMoneyMoved($held);
        $this->signIn(Role::BidAdmin);

        $this->post(route('bid-admin.approve', $held), ['reason' => 'Regular customer, checked the receipt.'])->assertSessionHas('status');
        $this->post(route('bid-admin.approve', $held), ['reason' => 'Double click.'])->assertSessionHas('error');
        $this->post(route('bid-admin.deny', $held), ['reason' => 'Other tab.', 'denial' => 'suspected_abuse'])->assertSessionHas('error');
        app(MerchantPayouts::class)->send($this->grind);
        $this->post(route('admin.reconciliation.retry'));

        $this->assertCount(1, $this->stripe->callsOf('transfer'));
        $this->assertSame(4000, $this->stripe->callsOf('transfer')[0]['amount']);
        $payout = MerchantPayout::where('redemption_id', $held->id)->sole();
        $this->assertSame(MerchantPayout::STATUS_SENT, $payout->status);
        $this->assertSame(1, RedemptionDecision::where('redemption_id', $held->id)->count());
        $this->assertTrue(app(TransferGate::class)->allows($held));

        $this->get('/bid-admin')->assertSee('AI held (risk 85) → approved by BID admin S.T. · '.now()->setTimezone('America/New_York')->format('M j').' → transferred '.$payout->stripe_transfer_id);
    }

    public function test_a_timeout_then_retry_reuses_the_redemptions_key(): void
    {
        $this->onboard($this->grind);
        $held = $this->heldAtGrind();
        $this->stripe->transferFailures = [['Could not reach Stripe.', false]];

        app(RedemptionReviewService::class)->approve($held, $this->admin(), 'Checked with the merchant.');
        app(MerchantPayouts::class)->send($this->grind);

        $keys = $this->stripe->idempotencyKeys;
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1], 'The retry asks Stripe with the same key, so Stripe cannot pay twice');
        $this->assertCount(1, $this->stripe->transfers);
    }

    public function test_deny_sends_nothing_and_returns_the_credit(): void
    {
        $this->onboard($this->grind);
        $employee = $this->employee('Alex Moreau', 10000);
        $before = $employee->balanceCents();
        $employerBefore = $this->employer->balanceCents();
        $this->aiHoldsRoundAmounts();
        $abuse = $this->redeem($employee, $this->grind, 4000);
        $mistake = $this->redeem($employee, $this->grind, 3000);

        app(RedemptionReviewService::class)->deny($abuse, $this->admin(), 'Same round amount every week.', DenialReason::SuspectedAbuse);
        app(RedemptionReviewService::class)->deny($mistake, $this->admin(), 'Merchant says it never happened.', DenialReason::NoPurchase);
        app(MerchantPayouts::class)->send($this->grind);

        $this->assertSame([], $this->stripe->callsOf('transfer'));
        $this->assertNoMoneyMoved($abuse->fresh());
        $this->assertNoMoneyMoved($mistake->fresh());
        $this->assertSame($before - 4000, $employee->balanceCents(), 'Abuse: not back with the employee');
        $this->assertSame($employerBefore + 4000, $this->employer->balanceCents(), 'Abuse: back with the employer');
        $this->assertSame(1, CreditLedgerEntry::where('redemption_id', $mistake->id)->where('type', CreditLedgerEntry::TYPE_REVIEW_REJECTED)->where('amount_cents', 3000)->count());
        $this->assertSame(2, CreditLedgerEntry::whereIn('redemption_id', [$abuse->id, $mistake->id])->where('type', CreditLedgerEntry::TYPE_REDEMPTION)->count(), 'The original debits stay');
    }

    public function test_an_ai_failure_or_invalid_reply_is_a_hold_and_never_transfers(): void
    {
        $this->onboard($this->grind);
        $employee = $this->employee('Alex Moreau', 10000);

        foreach ([new RuntimeException('API down'), '{"decision": "clear"}', 'not json'] as $reply) {
            $this->app->instance(ClaudeClient::class, new FakeClaudeClient($reply));
            $redemption = $this->redeem($employee, $this->grind, 437);

            $this->assertTrue($redemption->isUnderReview());
            $this->assertSame('fallback', $redemption->scored_by);
            $this->assertNoMoneyMoved($redemption);
            $this->assertSame('AI check failed → automatic hold', $redemption->auditTrail()[0]);
        }
    }

    public function test_the_gate_refuses_an_approved_row_without_an_ai_clear_or_a_persons_approval(): void
    {
        $this->onboard($this->grind);
        $this->app->instance(ClaudeClient::class, new FakeClaudeClient(new RuntimeException('API down')));
        $redemption = $this->redeem($this->employee('Alex Moreau', 10000), $this->grind, 437);

        // As if some future code flipped the row and credited the merchant, skipping review.
        Redemption::whereKey($redemption->id)->update(['status' => Redemption::STATUS_APPROVED]);
        app(CreditLedger::class)->creditMerchant($redemption->fresh());

        $this->assertFalse(app(TransferGate::class)->allows($redemption->fresh()));
        $this->assertNull(app(StripeFunding::class)->claim($redemption->fresh()));

        // Seeded history is never "cleared by the AI" either.
        $this->assertFalse(app(TransferGate::class)->allows(Redemption::where('scored_by', 'seed')->firstOrFail()));
    }

    public function test_with_the_gate_closed_no_flow_creates_a_transfer(): void
    {
        // Owed through Stripe while the merchant isn't onboarded yet.
        $this->aiHoldsRoundAmounts();
        $cleared = $this->redeem($this->employee('Alex Moreau', 10000), $this->grind, 437);
        $held = $this->redeem($this->employee('Alex Moreau'), $this->grind, 4000);
        $this->assertSame(MerchantPayout::STATUS_OWED, MerchantPayout::where('redemption_id', $cleared->id)->value('status'));

        $calls = 0;
        $this->app->instance(TransferGate::class, new class($calls) extends TransferGate
        {
            public function __construct(private int &$calls) {}

            public function allows(Redemption $redemption): bool
            {
                $this->calls++;

                return false;
            }
        });

        // Every way money can be sent: onboarding finishing (sync and webhook), Retry, a new clear, an approval.
        $this->stripe->accounts['acct_test_FG'] = ['id' => 'acct_test_FG'];
        $this->grind->update(['stripe_account_id' => 'acct_test_FG']);
        $this->postWebhook($this->stripeEvent('v2.core.account.updated', $this->stripe->completeOnboarding('acct_test_FG')));
        app(StripeOnboarding::class)->apply($this->grind->fresh(), $this->stripe->accounts['acct_test_FG']);
        app(MerchantPayouts::class)->send($this->grind->fresh());
        $this->signIn(Role::PlatformAdmin);
        $this->post(route('admin.reconciliation.retry'));
        $this->redeem($this->employee('Alex Moreau'), $this->grind, 512);
        app(RedemptionReviewService::class)->approve($held, $this->admin(), 'Checked with the merchant.');

        $this->assertSame([], $this->stripe->callsOf('transfer'));
        $this->assertGreaterThan(0, $calls, 'The gate was asked');
        $this->assertSame(MerchantPayout::STATUS_FAILED, MerchantPayout::where('redemption_id', $cleared->id)->value('status'));
    }

    public function test_only_the_payout_sender_creates_transfers_and_it_asks_the_gate_first(): void
    {
        // Every place in app/ that could create a Stripe transfer.
        $callers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() === 'php' && preg_match('/createTransfer\(|->transfers->create\(|Transfer::create\(/', file_get_contents($file->getPathname()))) {
                $callers[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }
        sort($callers);

        // The interface, the SDK wrapper and its notice guard only pass the call on; MerchantPayouts is the one caller.
        $this->assertSame([
            'Services/Payments/MerchantPayouts.php',
            'Services/Payments/NoticeSafeStripeGateway.php',
            'Services/Payments/StripeGateway.php',
            'Services/Payments/StripeSdkGateway.php',
        ], $callers);

        $source = file_get_contents(app_path('Services/Payments/MerchantPayouts.php'));
        $this->assertSame(1, substr_count($source, '->createTransfer('));
        $sendOne = substr($source, strpos($source, 'private function sendOne('));
        $this->assertLessThan(strpos($sendOne, '->createTransfer('), strpos($sendOne, '$this->gate->allows('), 'The gate is asked before Stripe is called');
        $this->assertStringNotContainsString('createTransfer', file_get_contents(app_path('Services/Payments/StripeFunding.php')));
    }

    public function test_reviewer_decisions_are_never_eval_labels(): void
    {
        $evalCode = [
            ...glob(app_path('Services/Fraud/FraudEval*.php')),
            ...glob(app_path('Services/Adjudication/*.php')),
            app_path('Http/Controllers/FraudEvalController.php'),
            ...glob(app_path('Console/Commands/*.php')),
        ];

        foreach ($evalCode as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString('RedemptionDecision', $source, basename($file));
            $this->assertStringNotContainsString('redemption_decisions', $source, basename($file));
        }

        $cases = hash_file('sha256', config('fraud.eval_cases_path'));
        $held = $this->heldAtGrind();
        app(RedemptionReviewService::class)->deny($held, $this->admin(), 'Same round amount every week.', DenialReason::SuspectedAbuse);
        $this->assertSame($cases, hash_file('sha256', config('fraud.eval_cases_path')));
        $this->get('/eval')->assertOk()->assertDontSee('Same round amount every week.');
    }

    public function test_judges_cannot_approve_deny_or_run_the_example_and_never_see_the_controls(): void
    {
        $held = $this->heldAtGrind();
        $judge = User::factory()->role(Role::Judge)->create();
        $redemptions = Redemption::count();

        $this->actingAs($judge)->get('/bid-admin')->assertOk()
            ->assertDontSee('Run a collusion example')
            ->assertDontSee('name="reason"', false)
            ->assertSee('AI held (risk 85) → waiting for a BID admin → no transfer while held');
        $this->actingAs($judge)->post(route('bid-admin.approve', $held), ['reason' => 'Looks fine to me.'])->assertForbidden();
        $this->actingAs($judge)->post(route('bid-admin.deny', $held), ['reason' => 'Looks wrong.', 'denial' => 'no_purchase'])->assertForbidden();
        $this->actingAs($judge)->post(route('bid-admin.collusion-example'))->assertForbidden();

        $this->assertTrue($held->fresh()->isUnderReview());
        $this->assertSame(0, RedemptionDecision::count());
        $this->assertSame($redemptions, Redemption::count());
    }

    public function test_reconciliation_still_matches_after_clears_approvals_and_denials(): void
    {
        $this->onboard($this->grind);
        $this->aiHoldsRoundAmounts();
        $employee = $this->employee('Alex Moreau', 15000);
        $this->redeem($employee, $this->grind, 437);
        $approved = $this->redeem($employee, $this->grind, 4000);
        $denied = $this->redeem($employee, $this->grind, 3000);
        app(RedemptionReviewService::class)->approve($approved, $this->admin(), 'Checked with the merchant.');
        app(RedemptionReviewService::class)->deny($denied, $this->admin(), 'Same round amount every week.', DenialReason::SuspectedAbuse);
        $this->signIn(Role::BidAdmin);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(88, 'hold', 'Round amount far above a café ticket.'));
        $this->post(route('bid-admin.collusion-example'));

        $sent = array_sum(array_column($this->stripe->transfers, 'amount'));
        $this->assertSame(4437, $sent);
        $this->stripe->paymentIntents = [[
            'id' => 'pi_test_gate', 'status' => 'succeeded', 'amount' => 300000, 'amount_received' => 300000, 'currency' => 'usd',
            'metadata' => ['lg_purchase_token' => 'tok-gate'],
        ]];
        $this->stripe->balance = ['available' => [['amount' => 300000 - $sent, 'currency' => 'usd']], 'pending' => [['amount' => 0, 'currency' => 'usd']]];

        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/reconciliation')->assertOk()->assertSee('data-reconciled="yes"', false);

        // And the ledger invariant: purchases − payouts − amounts under review = sum of all entries.
        $purchased = (int) CreditPurchase::whereIn('status', CreditPurchase::PAID_STATUSES)->sum('amount_cents');
        $paidOut = (int) MerchantPayout::paid()->sum('amount_cents');
        $held = (int) Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->sum('amount_cents');
        $this->assertSame($purchased - $paidOut - $held, (int) CreditLedgerEntry::sum('amount_cents'));
    }

    public function test_the_collusion_example_runs_the_ai_on_a_synthetic_round_amount_at_farragut_grind(): void
    {
        $this->onboard($this->grind);
        $fake = FakeClaudeClient::verdict(88, 'hold', 'Round amount far above a café ticket, most of the balance.');
        $this->app->instance(ClaudeClient::class, $fake);
        $jordan = Employee::orderBy('id')->firstOrFail();
        $jordanBefore = $jordan->balanceCents();
        $this->signIn(Role::BidAdmin);

        $this->get('/bid-admin')->assertSee('Run a collusion example');
        $response = $this->post(route('bid-admin.collusion-example'));

        $example = Redemption::latest('id')->firstOrFail();
        $response->assertRedirect(route('bid-admin.dashboard').'#redemption-'.$example->id);
        $this->assertCount(1, $fake->calls, 'One AI call');
        $this->assertSame($this->grind->id, $example->merchant_id);
        $this->assertContains($example->employee->display_name, DemoHistorySeeder::NAMES, 'A seeded synthetic person');
        $this->assertSame($jordanBefore, $jordan->balanceCents(), 'Never Jordan');
        $this->assertSame(0, $example->amount_cents % 1000);
        $this->assertGreaterThanOrEqual(2000, $example->amount_cents);
        $signals = json_decode($fake->calls[0]['user'], true);
        $this->assertTrue($signals['is_round_number']);
        $this->assertSame('above', $signals['amount_vs_category_range']);
        $this->assertTrue($example->isUnderReview());
        $this->assertNoMoneyMoved($example);

        $this->followRedirects($response)
            ->assertSee('Collusion example: '.Money::format($example->amount_cents).' at Farragut Grind')
            ->assertSee('The real AI held it.')
            ->assertSee('No money moves until a person approves it below.')
            ->assertDontSee($example->employee->display_name);
    }

    public function test_a_collusion_example_the_ai_clears_is_shown_as_an_honest_miss(): void
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(20, 'clear'));
        $this->signIn(Role::PlatformAdmin);

        $this->followingRedirects()->post(route('bid-admin.collusion-example'))
            ->assertSee('The real AI cleared it.')
            ->assertSee('An honest miss');
    }

    public function test_the_collusion_example_says_so_when_nobody_has_credit_left(): void
    {
        Employee::whereIn('display_name', DemoHistorySeeder::NAMES)->get()->each(function (Employee $e) {
            if ($e->balanceCents() > 0) {
                CreditLedgerEntry::create(['employee_id' => $e->id, 'amount_cents' => -$e->balanceCents(), 'type' => CreditLedgerEntry::TYPE_REDEMPTION, 'description' => 'Test: spent', 'created_at' => now()]);
            }
        });
        $redemptions = Redemption::count();
        $this->signIn(Role::BidAdmin);

        $this->post(route('bid-admin.collusion-example'))->assertSessionHas('error', fn ($m) => str_contains($m, 'Press Reset demo first'));
        $this->assertSame($redemptions, Redemption::count());
    }

    public function test_the_collusion_example_says_so_when_the_demo_merchant_is_missing(): void
    {
        $fake = FakeClaudeClient::verdict(88, 'hold');
        $this->app->instance(ClaudeClient::class, $fake);
        config(['demo.accounts.merchant.business' => 'A Merchant That Is Not Seeded']);
        $redemptions = Redemption::count();
        $this->signIn(Role::BidAdmin);

        $this->post(route('bid-admin.collusion-example'))->assertSessionHas('error', 'The demo merchant is missing. Press Reset demo first.');
        $this->assertSame($redemptions, Redemption::count());
        $this->assertSame([], $fake->calls, 'No AI call without a merchant.');
    }
}
