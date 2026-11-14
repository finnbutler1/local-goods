<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BidProgramFeePayment;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Payments\OperatingAccountSetup;
use App\Services\Payments\OperatingSweeps;
use App\Services\Payments\StripeReconciliation;
use App\Services\Solvency\SolvencyCheck;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PaysBidProgramFee;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * The BID program fee in the books (ADR 0044): Local Goods revenue on the
 * platform account, so customer funds and every employer, employee and
 * merchant balance are untouched; the solvency rule still balances exactly;
 * the operating sweep can move it; and Stripe reconciliation matches it both
 * ways and flags a refund made in the Stripe dashboard.
 */
class BidProgramFeeBooksTest extends TestCase
{
    use PaysBidProgramFee;
    use RefreshDatabase;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    private const FEE = 500000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->switchOnBidFee();
        $this->setUpDistrict(6000, '2026-10-01');
        $this->travelTo(Carbon::parse('2026-11-13 12:00', 'America/New_York'));
    }

    private function payTheFee(): BidProgramFeePayment
    {
        $this->signIn(Role::BidAdmin);
        $this->post(route('bid-admin.program-fee.pay'))->assertRedirect();
        $payment = BidProgramFeePayment::sole();
        $this->payBidFee($payment)->assertJsonPath('outcome', 'paid: BID program fee #'.$payment->id);

        return $payment->fresh();
    }

    /** @return array<string, mixed> every customer balance */
    private function balances(): array
    {
        return [
            'employees' => Employee::orderBy('id')->get()->map->balanceCents()->all(),
            'employer' => Employer::firstOrFail()->balanceCents(),
            'merchants' => Merchant::orderBy('id')->get()->map->payableCents()->all(),
        ];
    }

    public function test_it_is_operating_money_customer_funds_are_unchanged_and_solvency_balances(): void
    {
        $check = app(SolvencyCheck::class);
        $before = $check->now();
        $balances = $this->balances();
        $this->assertTrue($before->passes());

        $this->payTheFee();
        $after = $check->now();

        $this->assertSame($balances, $this->balances(), 'No employer, employee or merchant balance moved.');
        $this->assertSame($before->employerCents + $before->employeeCents + $before->merchantCents, $after->employerCents + $after->employeeCents + $after->merchantCents, 'Customer funds owed are unchanged.');
        $this->assertSame($before->heldRedemptionsCents, $after->heldRedemptionsCents);
        $this->assertSame($before->operatingCents + self::FEE, $after->operatingCents, 'It is Local Goods\' own money.');
        $this->assertSame($before->feesPaidInCents + self::FEE, $after->feesPaidInCents);
        $this->assertSame($before->stripeInCents + self::FEE, $after->stripeInCents, 'Stripe money in.');
        $this->assertTrue($after->passes(), (string) $after->problem());
        $this->assertTrue($after->isBalanced(), 'Money in hand still equals everything promised, exactly.');

        $history = $check->history();
        $this->assertTrue($history->final->passes());
        $this->assertSame([], $history->windows);
        $this->assertSame([], $check->recordsAgree());
    }

    public function test_the_operating_sweep_can_move_it_and_the_rule_still_holds(): void
    {
        app(OperatingAccountSetup::class)->create('127.0.0.1');
        $sweeps = app(OperatingSweeps::class);
        $before = $sweeps->sweepable();

        $this->payTheFee();
        $this->assertSame($before + self::FEE, $sweeps->sweepable());
        $this->assertSame(OperatingSweeps::channel(), 'stripe');

        $admin = User::where('role', Role::PlatformAdmin)->first() ?? $this->signIn(Role::PlatformAdmin);
        $sweeps->sweep($admin, self::FEE, 'Program fee for year 1', $sweeps->sweepable());

        $now = app(SolvencyCheck::class)->now();
        $this->assertTrue($now->passes(), (string) $now->problem());
        $this->assertLessThanOrEqual($now->stripeInCents, $now->stripeOutCents, 'The Stripe floor holds.');
        $this->assertSame([], app(SolvencyCheck::class)->recordsAgree());
    }

    public function test_reconciliation_matches_the_fee_and_counts_it_in_the_expected_balance(): void
    {
        $plain = app(StripeReconciliation::class)->report();
        $this->payTheFee();

        $report = app(StripeReconciliation::class)->report();

        $this->assertSame([], array_values(array_filter($report['mismatches'], fn ($m) => str_contains($m, 'program fee'))));
        $this->assertSame($plain['mismatches'], $report['mismatches'], 'Nothing new is wrong.');
        $this->assertSame(self::FEE, $report['stripe']['bid_fee_cents']);
        $this->assertSame([self::FEE, self::FEE], [$report['ledger']['bid_fee_cents'], $report['ledger']['bid_fee_line_cents']]);
        $this->assertSame($plain['expected_balance_cents'] + self::FEE, $report['expected_balance_cents']);
        $this->assertSame($plain['stripe']['purchase_cents'], $report['stripe']['purchase_cents'], 'Not counted as a credit purchase.');
    }

    public function test_reconciliation_flags_a_fee_payment_it_has_no_record_of(): void
    {
        $payment = $this->payTheFee();
        $this->stripe->paymentIntents[] = $this->bidFeeIntent($payment, ['id' => 'pi_test_unknown_fee'], ['lg_bid_fee_token' => 'f0000000-0000-4000-8000-000000000000']);

        $this->assertContains('Stripe has a $5,000.00 BID program fee payment (pi_test_unknown_fee) that Local Goods has no ledger record of (for example a program year paid twice, or a payment that did not match). Check before acting: Local Goods never refunds this fee in the app.',
            app(StripeReconciliation::class)->report()['mismatches']);
    }

    public function test_reconciliation_flags_a_paid_year_stripe_does_not_have(): void
    {
        $payment = $this->payTheFee();
        $this->stripe->paymentIntents = [];

        $this->assertContains('Local Goods counts the Golden Triangle BID program fee for program year 1 ($5,000.00) as paid through Stripe ('.$payment->stripe_payment_intent_id.'), but Stripe has no such successful payment since the last reset.',
            app(StripeReconciliation::class)->report()['mismatches']);
    }

    public function test_reconciliation_flags_a_refund_made_in_the_stripe_dashboard(): void
    {
        $payment = $this->payTheFee();
        $this->stripe->refunds[] = ['id' => 're_test_dashboard', 'object' => 'refund', 'amount' => self::FEE, 'currency' => 'usd',
            'payment_intent' => $payment->stripe_payment_intent_id, 'status' => 'succeeded', 'metadata' => [], 'created' => time()];

        $report = app(StripeReconciliation::class)->report();

        $this->assertContains('A $5,000.00 refund (re_test_dashboard) of the Golden Triangle BID program fee for program year 1 was made in the Stripe dashboard. Local Goods doesn\'t refund this fee and recorded nothing; check before acting.',
            $report['mismatches']);
        $this->assertTrue($payment->fresh()->isPaid(), 'Nothing is recorded automatically.');
    }

    public function test_where_the_money_is_names_the_fee_with_figures_for_staff_and_words_for_judges(): void
    {
        $this->payTheFee();

        $this->signIn(Role::PlatformAdmin);
        $this->get(route('money'))->assertOk()
            ->assertSee('1 program year of the BID annual program fee was paid in Stripe test mode ($5,000.00 in all).')
            ->assertSee('The BID program fee is charged in Stripe test mode: no real money.');

        $this->signIn(Role::Judge);
        $html = $this->get(route('money'))->assertOk()->assertSee('1 program year of the BID annual program fee was paid in Stripe test mode.')->getContent();
        $this->assertStringNotContainsString('$5,000.00', $html);
    }
}
