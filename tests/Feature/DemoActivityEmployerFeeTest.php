<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\DemoActivityAction;
use App\Models\DemoActivityDay;
use App\Models\Employer;
use App\Services\Payments\StripeReconciliation;
use App\Support\EmployerPlatformFee;
use App\Support\ParticipatingEmployees;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\RunsDemoActivity;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * The demo activity generator's own credit purchase (ADR 0032) with the
 * employer platform fee switched on (ADR 0042): the same requestPurchase
 * charges the month's fee, Stripe's test card is charged credits + fee,
 * the webhook writes the fee's own platform line once, reconciliation
 * matches, and a definite Stripe refusal cancels the purchase and frees
 * the month's fee for the next one.
 */
class DemoActivityEmployerFeeTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use RunsDemoActivity;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    /** A Monday in November: "last month" is October, which the fixed-date seed fills. */
    private const DAY = '2026-11-02';

    protected function setUp(): void
    {
        parent::setUp();
        $this->startDemoActivity();
        config(['payments.employer_platform_fee_enabled' => true]);
        $this->assertTrue(EmployerPlatformFee::charges());
    }

    private function expectedFeeCents(): int
    {
        $employees = ParticipatingEmployees::lastMonth(Employer::firstOrFail(), Carbon::parse(self::DAY.' 12:00', 'America/New_York'));
        $this->assertGreaterThan(0, $employees, 'The seed gave October allowances, so the fee is not empty.');

        return $employees * EmployerPlatformFee::unitCents();
    }

    /** @return Collection<int, CreditPurchase> */
    private function generatedPurchases()
    {
        return CreditPurchase::whereIn('id', DemoActivityAction::where('kind', DemoActivityAction::KIND_PURCHASE)->whereNotNull('credit_purchase_id')->pluck('credit_purchase_id'))
            ->orderBy('id')->get();
    }

    public function test_the_generated_purchase_carries_the_fee_and_everything_still_adds_up(): void
    {
        $fee = $this->expectedFeeCents();

        $this->runDay(self::DAY);

        $day = DemoActivityDay::whereDate('date', self::DAY)->firstOrFail();
        $this->assertNull($day->halted_reason, (string) $day->halted_reason);
        $this->assertSame([], $day->actions->where('status', DemoActivityAction::STATUS_FAILED)->pluck('error', 'slot')->all());

        $purchases = $this->generatedPurchases();
        $this->assertNotEmpty($purchases, 'The generator bought credits on the first day of the month.');
        $first = $purchases->first();

        // The month's fee is on the first purchase, copied from the setting.
        $this->assertSame($fee, (int) $first->employer_fee_cents);
        $this->assertSame(EmployerPlatformFee::unitCents(), (int) $first->employer_fee_unit_cents);
        $this->assertSame('2026-11', $first->employer_fee_month);
        $this->assertSame(EmployerPlatformFee::key(Employer::firstOrFail(), '2026-11'), $first->employer_fee_key);
        $this->assertSame(0, (int) $first->platform_fee_cents, 'The percent fee stays off');

        // Stripe's test card was charged credits + fee, and the webhook marked it paid.
        $payment = collect($this->stripe->callsOf('test_payment'))->firstWhere('metadata.lg_purchase_id', (string) $first->id);
        $this->assertSame($first->amount_cents + $fee, $payment['amount']);
        $this->assertSame($first->totalCents(), $payment['amount']);
        $this->assertSame(CreditPurchase::STATUS_PAID, $first->status);

        // Exactly one fee line, on the platform account; the credit line is credits only.
        $line = CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_EMPLOYER_PLATFORM_FEE)->sole();
        $this->assertTrue((bool) $line->platform);
        $this->assertSame($first->id, $line->credit_purchase_id);
        $this->assertSame($fee, (int) $line->amount_cents);
        $this->assertSame($first->amount_cents, (int) CreditLedgerEntry::where('credit_purchase_id', $first->id)->where('type', CreditLedgerEntry::TYPE_PURCHASE)->sum('amount_cents'));

        // The action log says the fee was added.
        $action = DemoActivityAction::where('credit_purchase_id', $first->id)->sole();
        $this->assertSame($first->amount_cents, (int) $action->amount_cents, 'The daily purchase cap counts credits only');
        $this->assertStringContainsString('employer platform fee', (string) $action->note);

        // Any later purchase this month carries no fee.
        foreach ($purchases->slice(1) as $later) {
            $this->assertSame(0, (int) $later->employer_fee_cents, $later->reference);
        }

        $this->assertLedgerInvariant();
        $this->assertNoNegativeBalances();
        $this->assertSame([], app(StripeReconciliation::class)->report()['mismatches']);
    }

    public function test_a_second_day_in_the_same_month_charges_no_second_fee(): void
    {
        $fee = $this->expectedFeeCents();

        $this->runDay(self::DAY);
        $this->runDay('2026-11-03');

        $this->assertSame(1, CreditPurchase::where('employer_fee_cents', '>', 0)->count());
        $this->assertSame($fee, (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_EMPLOYER_PLATFORM_FEE)->sum('amount_cents'));
        $this->assertLedgerInvariant();
        $this->assertSame([], app(StripeReconciliation::class)->report()['mismatches']);
    }

    public function test_a_definite_stripe_refusal_cancels_the_purchase_and_frees_the_fee_for_the_next_one(): void
    {
        $fee = $this->expectedFeeCents();
        $this->stripe->testPaymentFailure = ['Your card was declined (fake).', true];

        // Tick until the generator tries to buy (and Stripe refuses).
        for ($t = Carbon::parse(self::DAY.' 07:00', 'America/New_York'); $this->generatedPurchases()->isEmpty() && $t->format('H:i') < '19:00'; $t->addMinutes(5)) {
            $this->tickAt($t->format('Y-m-d H:i'));
        }

        $refused = $this->generatedPurchases()->sole();
        $this->assertSame(CreditPurchase::STATUS_CANCELLED, $refused->status);
        $this->assertSame($fee, (int) $refused->employer_fee_cents, 'It carried the fee when it was requested');
        $this->assertNull($refused->employer_fee_key, 'Cancelling frees the month\'s fee');
        $this->assertSame(0, CreditLedgerEntry::where('credit_purchase_id', $refused->id)->count(), 'Nothing reached the ledger');
        $this->assertSame(DemoActivityAction::STATUS_FAILED, DemoActivityAction::where('credit_purchase_id', $refused->id)->sole()->status);

        // The rest of the day: the next purchase carries the month's fee, once.
        $this->runDay(self::DAY);

        $retry = $this->generatedPurchases()->where('id', '!=', $refused->id)->first();
        $this->assertNotNull($retry, 'The generator bought again after the refusal.');
        $this->assertSame(CreditPurchase::STATUS_PAID, $retry->status);
        $this->assertSame($fee, (int) $retry->employer_fee_cents);
        $this->assertSame(EmployerPlatformFee::key(Employer::firstOrFail(), '2026-11'), $retry->employer_fee_key);
        $this->assertSame($fee, (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_EMPLOYER_PLATFORM_FEE)->sum('amount_cents'));

        $this->assertLedgerInvariant();
        $this->assertNoNegativeBalances();
        $this->assertSame([], app(StripeReconciliation::class)->report()['mismatches']);
    }
}
