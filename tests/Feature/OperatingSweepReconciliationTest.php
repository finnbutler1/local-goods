<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Bid;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\OperatingAccount;
use App\Models\OperatingSweep;
use App\Models\User;
use App\Services\Payments\OperatingAccountSetup;
use App\Services\Payments\OperatingSweeps;
use App\Services\Payments\StripeReconciliation;
use App\Services\Payments\StripeWebhookHandler;
use App\Support\StripeEnvironment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Reconciliation with two accounts (ADR 0034): sweeps are matched to
 * Stripe's transfers both ways, the customer funds balance it expects is net
 * of sweeps, and Local Goods Operating's own balance must equal the sweeps
 * sent to it. A reversed sweep is a mismatch and nothing is written.
 */
class OperatingSweepReconciliationTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    private const EARNED = 160;

    private CreditPurchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        app(OperatingAccountSetup::class)->create('127.0.0.1');
        config(['payments.platform_fee_percent' => '2.5']);
        $this->purchase = $this->stripePurchase(6400);
        // Set by Checkout in real use; reconciliation finds Stripe's payment by it.
        $this->purchase->forceFill(['payment_token' => 'fake-token-example'])->save();
        $this->stripe->paymentIntents = [['id' => $this->purchase->stripe_payment_intent_id, 'object' => 'payment_intent', 'status' => 'succeeded',
            'amount' => $this->purchase->totalCents(), 'amount_received' => $this->purchase->totalCents(),
            'metadata' => ['lg_purchase_token' => $this->purchase->payment_token] + StripeEnvironment::tag()]];
    }

    public function test_a_sent_sweep_matches_and_both_balances_are_as_expected(): void
    {
        $this->sweep();
        $this->stripe->balance = ['available' => [['amount' => $this->purchase->totalCents() - self::EARNED, 'currency' => 'usd']], 'pending' => []];

        $report = app(StripeReconciliation::class)->report();

        $this->assertSame([], $report['mismatches']);
        $this->assertSame($this->purchase->totalCents() - self::EARNED, $report['expected_balance_cents'], 'The customer funds balance is net of the sweep.');
        $this->assertSame([], array_values(array_filter($report['notes'], fn ($n) => str_contains($n, 'platform balance'))));
        $this->assertTrue($report['operating']['matches']);
        $this->assertSame(self::EARNED, $report['operating']['balance_cents']);
        $this->assertSame(0, $report['ledger']['transfer_cents'], 'A sweep is not a merchant transfer.');
        $this->assertSame(0, $report['stripe']['transfers']);
    }

    public function test_a_sweep_stripe_has_no_record_of_is_a_mismatch(): void
    {
        $sweep = $this->sweep();
        $this->stripe->transfers = [];

        // The sweep's real id: on MySQL ids keep counting between tests.
        $this->assertContains('Local Goods recorded sweep #'.$sweep->id.' of $1.60 to Local Goods Operating ('.$sweep->stripe_transfer_id.'), but Stripe has no such transfer since the last reset.',
            app(StripeReconciliation::class)->report()['mismatches']);
    }

    public function test_a_sweep_reversed_in_stripe_is_a_mismatch_and_nothing_is_written(): void
    {
        $sweep = $this->sweep();
        $this->stripe->transfers[0]['amount_reversed'] = self::EARNED;
        $lines = CreditLedgerEntry::count();

        $mismatches = app(StripeReconciliation::class)->report()['mismatches'];

        $this->assertContains('Stripe shows $1.60 of sweep transfer '.$sweep->stripe_transfer_id.' reversed back to the customer funds account, but the ledger still counts it as swept. A person decides what happens next; nothing is changed automatically.', $mismatches);
        $this->assertContains('Local Goods Operating holds $0.00 in Stripe, but the sweeps sent to it come to $1.60. Only Local Goods sends money there, so they should be equal. Check before changing anything.', $mismatches);
        $this->assertSame($lines, CreditLedgerEntry::count());

        // The webhook for it changes nothing either: it is not a merchant transfer.
        $this->assertStringStartsWith('ignored', app(StripeWebhookHandler::class)->handle($this->stripeEvent('transfer.reversed', $this->stripe->transfers[0])));
        $this->assertSame($lines, CreditLedgerEntry::count());
        $this->assertSame(OperatingSweep::STATUS_SENT, $sweep->fresh()->status);
    }

    public function test_an_unknown_sweep_transfer_is_a_mismatch_and_another_environments_is_not_counted(): void
    {
        $operating = OperatingAccount::current()->stripe_account_id;
        $this->stripe->transfers[] = ['id' => 'tr_test_unknown', 'object' => 'transfer', 'amount' => 50, 'destination' => $operating,
            'metadata' => ['lg_sweep_key' => 'lg-sweep-fake-example'] + StripeEnvironment::tag()];
        $this->stripe->transfers[] = ['id' => 'tr_test_elsewhere', 'object' => 'transfer', 'amount' => 70, 'destination' => 'acct_test_elsewhere',
            'metadata' => ['lg_sweep_key' => 'lg-sweep-fake-other', 'lg_env' => 'scratch', 'lg_instance' => 'not-this-one']];

        $report = app(StripeReconciliation::class)->report();

        $this->assertContains('Stripe has a $0.50 sweep transfer (tr_test_unknown) that Local Goods has no record of.', $report['mismatches']);
        $this->assertSame([], array_values(array_filter($report['mismatches'], fn ($m) => str_contains($m, 'tr_test_elsewhere'))));
        $this->assertSame(70, $report['other']['transfer_cents']);
    }

    public function test_a_lost_answer_shows_until_retry_records_it_with_the_same_key(): void
    {
        $this->stripe->transferFailures = [['Stripe did not answer in time.', false]];
        $sweep = $this->sweep();
        $this->assertSame(OperatingSweep::STATUS_PENDING, $sweep->status);
        // Stripe made it after all: the fake remembers keys like Stripe, so put it where a list would find it.
        $this->stripe->transfers[] = ['id' => 'tr_test_lost', 'object' => 'transfer', 'amount' => self::EARNED,
            'destination' => OperatingAccount::current()->stripe_account_id, 'metadata' => ['lg_sweep_key' => $sweep->idempotency_key] + StripeEnvironment::tag()];

        $report = app(StripeReconciliation::class)->report();
        $this->assertContains('Stripe made sweep transfer tr_test_lost of $1.60 for sweep #'.$sweep->id.', but Local Goods never got the answer. Press Retry owed transfers to record it (same key, never paid twice).', $report['mismatches']);
        $this->assertNotEmpty(array_filter($report['notes'], fn ($n) => str_starts_with($n, 'Sweep #'.$sweep->id.' of $1.60 to Local Goods Operating is reserved')));

        $this->signIn(Role::PlatformAdmin);
        $this->post(route('admin.reconciliation.retry'))->assertSessionHas('status', fn ($m) => str_ends_with($m, 'Sweep to Local Goods Operating: sent.'));
        $this->assertSame(OperatingSweep::STATUS_SENT, $sweep->fresh()->status);
    }

    public function test_after_a_reset_a_balance_left_from_earlier_sweeps_is_a_note_not_a_mismatch(): void
    {
        OperatingAccount::current()->forceFill(['created_at' => Bid::orderBy('id')->value('created_at')->copy()->subDay()])->save();
        // Stripe still holds $3.00 swept before the reset; the ledger no longer knows those sweeps.
        $this->stripe->accountBalances = [OperatingAccount::current()->stripe_account_id => 300];

        $report = app(StripeReconciliation::class)->report();

        $this->assertNull($report['operating']['mismatch']);
        $this->assertStringContainsString('a Reset demo since the account was made cleared earlier sweeps', (string) $report['operating']['note']);
    }

    public function test_a_balance_stripe_cant_read_is_a_note_and_the_page_still_opens(): void
    {
        $this->stripe->accountBalanceFailure = 'Stripe could not be reached.';
        $this->signIn(Role::PlatformAdmin);

        $this->get(route('admin.reconciliation'))->assertOk()->assertSee('data-operating-check="unknown"', false)
            ->assertSee('Couldn&#039;t read Local Goods Operating&#039;s balance from Stripe just now', false);
    }

    public function test_the_page_shows_the_operating_check(): void
    {
        $this->sweep();
        $this->signIn(Role::PlatformAdmin);

        $this->get(route('admin.reconciliation'))->assertOk()->assertSee('data-operating-check="yes"', false)->assertSee('Sweeps sent to it (ledger)');
    }

    private function sweep(): OperatingSweep
    {
        $admin = User::where('role', Role::PlatformAdmin)->first() ?? $this->signIn(Role::PlatformAdmin);

        return app(OperatingSweeps::class)->sweep($admin, self::EARNED, 'Fees earned this week', self::EARNED);
    }
}
