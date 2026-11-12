<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\CreditLedger;
use App\Services\Payments\OperatingAccountSetup;
use App\Services\Payments\OperatingSweeps;
use App\Services\Payments\StripeCheckout;
use App\Services\Payments\StripeReconciliation;
use App\Services\Solvency\SolvencyCheck;
use App\Support\StripeEnvironment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * The employer platform fee (ADR 0042), charged once a month on the first
 * credit purchase: N participating employees (last month) × the projection's
 * base price, on its own Checkout line and its own platform ledger line,
 * written only once Stripe confirms the payment. Never credits, never
 * customer funds, never refundable. With the flag off (the default) nothing
 * changes.
 */
class EmployerPlatformFeeChargeTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    /** Three employees got credits in February 2027; the purchases are in March, at $6.00 each. */
    private const FEE = 1800;

    private Employer $employer;

    private CreditLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $this->withoutSeededCorrections();
        $this->employer = Employer::firstOrFail();
        $this->ledger = app(CreditLedger::class);

        $this->participateIn('2027-02-10 12:00', 3);
        $this->travelTo(Carbon::parse('2027-03-10 12:00', 'America/New_York'));
    }

    /** Credits for the first $count employees at this DC time (a moment in that month). */
    private function participateIn(string $dcTime, int $count): void
    {
        foreach ($this->employer->employees()->orderBy('id')->take($count)->get() as $employee) {
            $this->ledger->allocate($this->employer, $employee, 100, Carbon::parse($dcTime, 'America/New_York')->utc());
        }
    }

    private function on(): void
    {
        config(['payments.employer_platform_fee_enabled' => true]);
    }

    private function request(int $cents = 40000, string $ref = 'MPG-FEE-1'): CreditPurchase
    {
        return $this->ledger->requestPurchase($this->employer, $cents, $ref)->fresh();
    }

    private function paidByStripe(CreditPurchase $purchase, string $charge = 'ch_test_fee'): CreditPurchase
    {
        $this->assertTrue($this->ledger->markPurchasePaidByStripe($purchase, 'pi_'.$charge, $charge, $purchase->totalCents(), 'usd'));

        return $purchase->fresh();
    }

    private function feeLines(): int
    {
        return CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_EMPLOYER_PLATFORM_FEE)->count();
    }

    private function assertSolventEverywhere(bool $invariant = true): void
    {
        $check = app(SolvencyCheck::class);
        $now = $check->now();
        $this->assertTrue($now->passes(), (string) $now->problem());
        $history = $check->history();
        $this->assertSame([], $history->windows);
        $this->assertSame([], $history->mismatches);
        $this->assertSame($now->toArray(), $history->final->toArray());
        $this->assertSame([], $check->recordsAgree());
        if ($invariant) {
            $this->assertLedgerInvariant();
        }
    }

    // --- Off (the default) -------------------------------------------------

    public function test_with_the_flag_off_a_purchase_checkout_and_ledger_are_exactly_as_before(): void
    {
        $this->fakeStripe();
        $purchase = $this->request();

        $this->assertSame(0, $purchase->employer_fee_cents);
        $this->assertSame(0, $purchase->employer_fee_employees);
        $this->assertSame(0, $purchase->employer_fee_unit_cents);
        $this->assertNull($purchase->employer_fee_month);
        $this->assertNull($purchase->employer_fee_key);
        $this->assertSame(40000, $purchase->totalCents());

        app(StripeCheckout::class)->start($purchase);
        [$session] = $this->stripe->callsOf('checkout');
        $this->assertCount(1, $session['line_items']);
        $this->assertSame(StripeCheckout::CUSTOMER_FUNDS_MESSAGE, $session['custom_text']['submit']['message']);

        $this->paidByStripe($purchase);
        $this->assertSame(0, $this->feeLines());
        $this->assertSame(0, (int) CreditLedgerEntry::where('platform', true)->sum('amount_cents'));
        $this->assertSolventEverywhere();
    }

    // --- When it is charged --------------------------------------------------

    public function test_the_first_purchase_of_the_month_carries_the_fee_for_last_months_participants(): void
    {
        $this->on();
        $purchase = $this->request();

        $this->assertSame(self::FEE, $purchase->employer_fee_cents);
        $this->assertSame(3, $purchase->employer_fee_employees);
        $this->assertSame(600, $purchase->employer_fee_unit_cents);
        $this->assertSame('2027-03', $purchase->employer_fee_month);
        $this->assertSame($this->employer->id.':2027-03', $purchase->employer_fee_key);
        $this->assertSame(0, $purchase->platform_fee_cents, 'The percent fee stays off');
        $this->assertSame(40000 + self::FEE, $purchase->totalCents());
        $this->assertSame(0, $this->feeLines(), 'Asking to buy writes no ledger line');
    }

    public function test_later_purchases_that_month_add_no_fee_and_next_month_charges_again(): void
    {
        $this->on();
        $first = $this->request(40000, 'MPG-FEE-1');
        $second = $this->request(20000, 'MPG-FEE-2');
        $this->paidByStripe($first, 'ch_one');
        $third = $this->request(10000, 'MPG-FEE-3');

        $this->assertSame(self::FEE, $first->employer_fee_cents);
        $this->assertSame(0, $second->employer_fee_cents);
        $this->assertNull($second->employer_fee_key);
        $this->assertSame(0, $third->employer_fee_cents);

        // April: the five who got credits in March.
        $this->participateIn('2027-03-15 12:00', 5);
        $this->travelTo(Carbon::parse('2027-04-02 09:00', 'America/New_York'));
        $april = $this->request(10000, 'MPG-FEE-4');
        $this->assertSame(5 * 600, $april->employer_fee_cents);
        $this->assertSame('2027-04', $april->employer_fee_month);
    }

    public function test_a_cancelled_fee_purchase_passes_the_fee_to_the_next_purchase(): void
    {
        $this->on();
        $first = $this->request(40000, 'MPG-FEE-1');
        $this->assertSame(1, CreditPurchase::cancelIfPending($first->id));
        $this->assertNull($first->fresh()->employer_fee_key);
        $this->assertSame(self::FEE, $first->fresh()->employer_fee_cents, 'The cancelled row keeps what it would have charged');

        $next = $this->request(40000, 'MPG-FEE-2');
        $this->assertSame(self::FEE, $next->employer_fee_cents);
        $this->assertSame(0, CreditPurchase::cancelIfPending($this->paidByStripe($next)->id), 'A paid purchase is never cancelled');
    }

    public function test_cancelling_from_the_dashboard_frees_the_fee(): void
    {
        $this->on();
        $this->fakeStripe();
        $this->signIn(Role::EmployerAdmin);
        $this->post('/employer/purchases', ['purchase_amount' => '400']);
        $first = CreditPurchase::latest('id')->firstOrFail();
        $this->assertSame(self::FEE, $first->employer_fee_cents);

        $this->post("/employer/purchases/{$first->id}/cancel")->assertRedirect();

        $this->assertTrue($first->fresh()->isCancelled());
        $this->assertNull($first->fresh()->employer_fee_key);
        $this->assertSame(self::FEE, $this->request()->employer_fee_cents);
    }

    public function test_the_database_refuses_a_second_fee_for_the_same_month(): void
    {
        $this->on();
        $first = $this->request();

        $this->expectException(UniqueConstraintViolationException::class);
        CreditPurchase::create([
            'employer_id' => $this->employer->id, 'amount_cents' => 100, 'reference' => 'MPG-FEE-RACE', 'purchased_at' => now(),
            'status' => CreditPurchase::STATUS_PENDING, 'employer_fee_cents' => self::FEE, 'employer_fee_key' => $first->employer_fee_key,
        ]);
    }

    public function test_no_fee_when_nobody_took_part_last_month(): void
    {
        $this->on();
        $this->travelTo(Carbon::parse('2027-06-10 12:00', 'America/New_York'));

        $purchase = $this->request();

        $this->assertSame(0, $purchase->employer_fee_cents);
        $this->assertNull($purchase->employer_fee_key, 'The month stays open for a later purchase');
        $this->assertSame(0, $this->feeLines());
    }

    public function test_no_fee_while_the_percent_fee_is_also_on(): void
    {
        $this->on();
        config(['payments.platform_fee_percent' => '2.5']);

        $purchase = $this->request();

        $this->assertSame(0, $purchase->employer_fee_cents, 'Both on is refused, so neither path adds this fee');
    }

    public function test_the_price_is_fixed_when_the_purchase_is_requested(): void
    {
        $this->on();
        $purchase = $this->request();
        config(['projections.assumptions.employer_platform_fee.base' => 10]);

        $this->assertSame(self::FEE, $this->paidByStripe($purchase)->employer_fee_cents);
        $this->assertSame(self::FEE, (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_EMPLOYER_PLATFORM_FEE)->sum('amount_cents'));
    }

    // --- Stripe: only after payment_intent.succeeded --------------------------

    public function test_checkout_shows_a_separate_platform_fee_line_and_the_webhook_writes_it_once(): void
    {
        $this->on();
        $this->fakeStripe();
        $this->signIn(Role::EmployerAdmin);
        $this->post('/employer/purchases', ['purchase_amount' => '400']);
        $purchase = CreditPurchase::latest('id')->firstOrFail();
        $entries = CreditLedgerEntry::count();

        $this->postJson("/employer/purchases/{$purchase->id}/checkout")->assertOk();
        [$session] = $this->stripe->callsOf('checkout');
        $this->assertCount(2, $session['line_items']);
        $this->assertSame(40000, $session['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(self::FEE, $session['line_items'][1]['price_data']['unit_amount']);
        $this->assertSame('Platform fee for March 2027: 3 participating employees × $6.00', $session['line_items'][1]['price_data']['product_data']['name']);
        $this->assertStringEndsWith(StripeCheckout::EMPLOYER_FEE_MESSAGE, $session['custom_text']['submit']['message']);
        $this->assertLessThanOrEqual(1200, mb_strlen($session['custom_text']['submit']['message']), "Stripe's limit for custom_text");

        $this->stripe->completeSession(array_key_last($this->stripe->sessions));
        $pi = [
            'id' => 'pi_test_fee', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 40000 + self::FEE, 'amount_received' => 40000 + self::FEE,
            'currency' => 'usd', 'latest_charge' => 'ch_test_fee', 'transfer_group' => $session['payment_intent_data']['transfer_group'],
            'metadata' => $session['payment_intent_data']['metadata'],
        ];

        // A failed attempt first: nothing is written.
        $failed = $this->stripeEvent('payment_intent.payment_failed', ['status' => 'requires_payment_method', 'last_payment_error' => ['message' => 'Your card was declined.']] + $pi, id: 'evt_fee_failed');
        $this->postWebhook($failed)->assertOk();
        $this->assertSame($entries, CreditLedgerEntry::count(), 'A failed payment adds nothing');
        $this->assertTrue($purchase->fresh()->isPending());

        $event = $this->stripeEvent('payment_intent.succeeded', $pi, id: 'evt_fee_paid');
        $this->postWebhook($event)->assertOk()->assertJson(['outcome' => 'paid: '.$purchase->reference]);
        $this->postWebhook($event)->assertOk()->assertJson(['outcome' => 'duplicate: already handled, nothing changed']);

        $this->assertSame(1, $this->feeLines());
        $line = CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_EMPLOYER_PLATFORM_FEE)->firstOrFail();
        $this->assertTrue((bool) $line->platform);
        $this->assertNull($line->employer_id, 'Never on the employer\'s balance');
        $this->assertSame(self::FEE, $line->amount_cents);
        $this->assertSame($purchase->id, $line->credit_purchase_id);
        $this->assertStringContainsString('Employer platform fee for 2027-03: 3 participating employees × $6.00', $line->description);
        $this->assertSame(40000, (int) CreditLedgerEntry::where('credit_purchase_id', $purchase->id)->where('type', CreditLedgerEntry::TYPE_PURCHASE)->sum('amount_cents'));

        // Reconciliation: Stripe received credits + fee, and the ledger says the same.
        $this->stripe->paymentIntents = [$pi];
        $this->stripe->balance = ['available' => [['amount' => 40000 + self::FEE, 'currency' => 'usd']], 'pending' => []];
        $this->assertSame([], app(StripeReconciliation::class)->report()['mismatches']);
        $this->assertSolventEverywhere();
    }

    public function test_a_payment_of_only_the_credits_is_refused(): void
    {
        $this->on();
        $purchase = $this->request();

        try {
            $this->ledger->markPurchasePaidByStripe($purchase, 'pi_short', 'ch_short', 40000, 'usd');
            $this->fail('A payment without the fee must be refused');
        } catch (InvalidArgumentException) {
        }
        $this->assertTrue($purchase->fresh()->isPending());
        $this->assertSame(0, $this->feeLines());
    }

    public function test_reconciliation_catches_a_missing_fee_line(): void
    {
        $this->on();
        $this->fakeStripe();
        $purchase = $this->paidByStripe($this->request());
        $this->stripe->paymentIntents = [['id' => 'pi_ch_test_fee', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 40000 + self::FEE,
            'amount_received' => 40000 + self::FEE, 'currency' => 'usd', 'latest_charge' => 'ch_test_fee', 'created' => now()->getTimestamp(),
            'metadata' => ['lg_purchase_token' => 'tok_fake_example'] + StripeEnvironment::tag()]];
        $this->stripe->balance = ['available' => [['amount' => 40000 + self::FEE, 'currency' => 'usd']], 'pending' => []];
        $this->assertSame([], app(StripeReconciliation::class)->report()['mismatches'], 'Matches before the change');

        // As if the fee had been charged twice over but only one ledger line existed.
        $purchase->forceFill(['employer_fee_cents' => 2 * self::FEE])->save();

        $this->assertContains(
            'Platform fees: Stripe-paid purchases charged $36.00 in platform fees, but the platform account in the ledger has $18.00 for them.',
            app(StripeReconciliation::class)->report()['mismatches'],
        );
        $this->assertNotSame([], app(SolvencyCheck::class)->recordsAgree());
    }

    public function test_simulated_payment_writes_the_fee_line_marked_simulated(): void
    {
        $this->on();
        $purchase = $this->ledger->payPurchase($this->request());

        $line = CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_EMPLOYER_PLATFORM_FEE)->sole();
        $this->assertSame(self::FEE, $line->amount_cents);
        $this->assertStringContainsString('simulated, no real money', $line->description);
        $this->assertSame(CreditPurchase::STATUS_SIMULATED, $purchase->status);
        $this->assertSolventEverywhere();
    }

    // --- Customer funds, solvency and the operating sweep ----------------------

    public function test_the_fee_is_operating_money_and_customer_funds_rise_by_the_credits_only(): void
    {
        $this->on();
        $before = app(SolvencyCheck::class)->now();

        $this->paidByStripe($this->request());

        $after = app(SolvencyCheck::class)->now();
        $this->assertSame($before->customerFundsCents() + 40000, $after->customerFundsCents(), 'The fee is never customer funds');
        $this->assertSame($before->operatingCents + self::FEE, $after->operatingCents);
        $this->assertSame($before->inHandCents() + 40000 + self::FEE, $after->inHandCents());
        $this->assertSame($before->feesPaidInCents + self::FEE, $after->feesPaidInCents);
        $this->assertSame($before->employerCents + 40000, $after->employerCents, 'The employer\'s balance gets the credits only');
        $this->assertSolventEverywhere();
    }

    public function test_the_fee_can_be_swept_to_operating_and_everything_still_balances(): void
    {
        $this->on();
        $this->fakeStripe();
        app(OperatingAccountSetup::class)->create('127.0.0.1');
        $purchase = $this->paidByStripe($this->request());
        $sweeps = app(OperatingSweeps::class);

        $this->assertSame(self::FEE, $sweeps->stripeEarnedUnswept());
        $this->assertSame(self::FEE, $sweeps->sweepable());
        $sweeps->sweep($this->admin(), self::FEE, 'Employer platform fee for March', self::FEE);

        $this->assertSame(0, $sweeps->sweepable());
        $this->assertSame(self::FEE, $this->stripe->callsOf('transfer')[0]['amount']);
        $this->assertSolventEverywhere(invariant: false); // MakesCorrections' invariant predates sweeps; the solvency rule covers them.

        $this->stripe->paymentIntents = [['id' => $purchase->stripe_payment_intent_id, 'object' => 'payment_intent', 'status' => 'succeeded',
            'amount' => $purchase->totalCents(), 'amount_received' => $purchase->totalCents(), 'currency' => 'usd',
            'latest_charge' => $purchase->stripe_charge_id, 'created' => now()->getTimestamp(), 'metadata' => ['lg_purchase_token' => 'tok_fake_example'] + StripeEnvironment::tag()]];
        $this->stripe->balance = ['available' => [['amount' => 40000, 'currency' => 'usd']], 'pending' => []];
        $this->assertSame([], app(StripeReconciliation::class)->report()['mismatches']);
    }

    // --- Never refundable, never pays a merchant -------------------------------

    public function test_the_fee_is_never_refunded_and_never_funds_a_merchant_transfer(): void
    {
        $this->on();
        $this->fakeStripe();
        $purchase = $this->paidByStripe($this->request(20000));
        $this->assertSame(self::FEE, $purchase->employer_fee_cents);
        $this->assertSame(20000, $purchase->unclaimedCents(), 'Only the credits can fund a transfer');
        $this->assertSame(20000, $purchase->refundableCents(), 'Only the credits can be refunded');

        $request = Correction::create([
            'type' => Correction::TYPE_EMPLOYER_REFUND, 'status' => Correction::STATUS_REQUESTED,
            'open_key' => Correction::openKeyFor(Correction::TYPE_EMPLOYER_REFUND, $this->employer->id),
            'requested_by_role' => Role::EmployerAdmin->value, 'reason_category' => 'bought_too_much', 'employer_id' => $this->employer->id,
        ]);
        app(CorrectionDecisions::class)->approveEmployerRefund($request, $this->admin(), 'Unspent credits back to the card.', 20000);

        $this->assertSame(20000, $this->stripe->callsOf('refund')[0]['amount']);
        $this->assertSame(self::FEE, (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_EMPLOYER_PLATFORM_FEE)->sum('amount_cents'), 'The fee line stays');
    }

    // --- Where the Money Is ----------------------------------------------------

    public function test_where_the_money_is_names_the_fee_with_figures_for_staff_and_words_for_judges(): void
    {
        $this->on();
        $this->paidByStripe($this->request());

        $this->signIn(Role::PlatformAdmin);
        $this->get('/where-the-money-is')->assertOk()
            ->assertSee('1 credit purchase paid the monthly employer platform fee ($18.00 in all).')
            ->assertSee('data-charges-now="yes"', false);

        config(['reviewers.judging_ends_at' => '2027-12-31 23:59']); // the purchase is in March 2027
        $this->signIn(Role::Judge);
        $this->get('/where-the-money-is')->assertOk()
            ->assertSee('1 credit purchase paid the monthly employer platform fee.')
            ->assertDontSee('$18.00 in all');
    }
}
