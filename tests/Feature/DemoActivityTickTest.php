<?php

namespace Tests\Feature;

use App\Enums\Occasion;
use App\Models\Correction;
use App\Models\CreditAllocation;
use App\Models\CreditPurchase;
use App\Models\DemoActivityAction;
use App\Models\DemoActivityDay;
use App\Models\Employee;
use App\Models\MerchantPayout;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Models\RedemptionDecision;
use App\Services\Payments\StripeReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\RunsDemoActivity;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The demo activity generator (ADR 0032) end to end against fake Stripe
 * and a fake AI: a whole weekday of ticks goes only through the normal
 * services, buys credits through the webhook, pays merchants by Stripe
 * transfer, and leaves the ledger, balances and reconciliation exactly right.
 */
class DemoActivityTickTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use RunsDemoActivity;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startDemoActivity();
    }

    public function test_a_whole_day_goes_through_the_normal_services_and_everything_still_adds_up(): void
    {
        $this->runDay('2026-10-26');

        $day = DemoActivityDay::whereDate('date', '2026-10-26')->firstOrFail();
        $this->assertNull($day->halted_reason, (string) $day->halted_reason);
        $actions = $day->actions;
        $this->assertSame([], $actions->where('status', DemoActivityAction::STATUS_FAILED)->pluck('error', 'slot')->all());

        // Credits bought with Stripe's test card and paid by the webhook.
        $purchase = $actions->firstWhere('kind', DemoActivityAction::KIND_PURCHASE)->purchase;
        $this->assertSame(CreditPurchase::STATUS_PAID, $purchase->status);

        // Every planned redemption ran (or was skipped with a reason) and made exactly one redemption.
        $planned = collect($day->plan)->where('kind', 'redemption');
        $done = $actions->where('kind', DemoActivityAction::KIND_REDEMPTION)->where('status', DemoActivityAction::STATUS_DONE);
        $this->assertSame($planned->count(), $actions->where('kind', DemoActivityAction::KIND_REDEMPTION)->count());
        $this->assertGreaterThanOrEqual($planned->count() - 2, $done->count());
        $this->assertCount($done->count(), Redemption::whereIn('id', $done->pluck('redemption_id'))->get());

        // Both ways of paying, tax on itemised orders, real timestamps at the planned time.
        $redemptions = Redemption::whereIn('id', $done->pluck('redemption_id'))->with('items')->get();
        $this->assertEqualsCanonicalizing([Redemption::CHANNEL_IN_PERSON, Redemption::CHANNEL_TO_GO], $redemptions->pluck('channel')->unique()->values()->all());
        $this->assertTrue($redemptions->contains(fn ($r) => $r->items->isNotEmpty() && $r->tax_cents > 0));
        $this->assertSame($redemptions->where('channel', Redemption::CHANNEL_IN_PERSON)->count(), PayCode::where('status', PayCode::STATUS_USED)->whereIn('redemption_id', $redemptions->pluck('id'))->count());
        foreach ($done as $action) {
            $this->assertTrue($action->redemption->created_at->gte($action->planned_for), $action->slot.' ran before its time');
            $this->assertLessThan(10, $action->planned_for->diffInMinutes($action->redemption->created_at), $action->slot.' ran late');
        }

        // Merchants were paid by Stripe test transfer, funded by the Stripe-paid purchase.
        $this->assertSame($redemptions->count(), MerchantPayout::where('status', MerchantPayout::STATUS_SENT)->whereIn('redemption_id', $redemptions->pluck('id'))->count());
        $this->assertGreaterThanOrEqual(3, $redemptions->pluck('merchant_id')->unique()->count());

        // Jordan and the planted collusion patterns are never touched.
        $excluded = Employee::whereIn('display_name', config('demo_activity.excluded_employees'))->pluck('id');
        $this->assertCount(5, $excluded);
        $this->assertSame(0, $redemptions->whereIn('employee_id', $excluded)->count());

        $this->assertLedgerInvariant();
        $this->assertNoNegativeBalances();
        $this->assertSame([], app(StripeReconciliation::class)->report()['mismatches']);
    }

    public function test_running_twice_at_the_same_time_does_nothing_more(): void
    {
        $this->tickAt('2026-10-26 13:00');
        $counts = [DemoActivityAction::count(), Redemption::count(), CreditPurchase::count(), count($this->stripe->calls)];

        $this->tickAt('2026-10-26 13:00');
        $this->tickAt('2026-10-26 13:00');
        $this->tickAt('2026-10-26 13:00');
        $again = [DemoActivityAction::count(), Redemption::count(), CreditPurchase::count(), count($this->stripe->calls)];

        // Only further slots that were already due (three a tick) may run; none twice.
        $this->assertSame(DemoActivityAction::count(), DemoActivityAction::get()->unique(fn ($a) => $a->day_id.':'.$a->slot)->count());
        $this->assertSame(DemoActivityAction::whereNotNull('redemption_id')->count(), DemoActivityAction::whereNotNull('redemption_id')->distinct()->count('redemption_id'));
        $this->assertGreaterThanOrEqual($counts[1], $again[1]);
        $this->assertSame(1, DemoActivityDay::count());

        // Once everything due has run, another tick at the same time changes nothing.
        for ($i = 0; $i < 10; $i++) {
            $this->tickAt('2026-10-26 13:00');
        }
        $settled = [DemoActivityAction::count(), Redemption::count(), CreditPurchase::count()];
        $this->tickAt('2026-10-26 13:00');
        $this->assertSame($settled, [DemoActivityAction::count(), Redemption::count(), CreditPurchase::count()]);
    }

    public function test_only_slots_that_are_due_run(): void
    {
        $this->tickAt('2026-10-26 07:00');
        $this->tickAt('2026-10-26 07:05');

        $day = DemoActivityDay::firstOrFail();
        $early = collect($day->plan)->filter(fn ($s) => $s['at'] <= '07:05')->pluck('slot');
        $this->assertEqualsCanonicalizing($early->all(), $day->actions()->where('kind', '!=', DemoActivityAction::KIND_PURCHASE)->pluck('slot')->all());
    }

    public function test_a_held_payment_waits_for_a_person_and_the_generator_never_decides(): void
    {
        $this->app->instance(\App\Services\Ai\ClaudeClient::class, FakeClaudeClient::verdict(85, 'hold', 'Round amount far above the usual.'));

        $this->runDay('2026-10-26');

        $held = Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->where('scored_by', '!=', 'seed')->get();
        $this->assertNotEmpty($held);
        $this->assertSame(0, RedemptionDecision::count(), 'Nothing decided by the generator');
        $this->assertSame(0, MerchantPayout::whereIn('redemption_id', $held->pluck('id'))->count(), 'No money moves for a held payment');

        // More than five waiting: no new redemptions today, and it says why.
        $day = DemoActivityDay::firstOrFail();
        $this->assertStringContainsString('waiting for a person', (string) $day->halted_reason);
        $this->assertSame(6, $held->count());
        $this->assertLedgerInvariant();
    }

    public function test_the_first_weekday_of_a_month_gives_this_months_allowances_through_the_ledger(): void
    {
        $this->runDay('2026-11-02');

        $allowances = CreditAllocation::where('occasion', Occasion::Monthly->value)->where('allocated_at', '>=', '2026-11-01')->get();
        $this->assertCount(Employee::count(), $allowances);
        $this->assertSame(Employee::count(), $allowances->pluck('employee_id')->unique()->count(), 'Once each');
        $this->assertTrue(CreditPurchase::where('status', CreditPurchase::STATUS_PAID)->exists());

        // The next day gives none again.
        $this->runDay('2026-11-03');
        $this->assertSame($allowances->count(), CreditAllocation::where('occasion', Occasion::Monthly->value)->where('allocated_at', '>=', '2026-11-01')->count());

        $this->assertLedgerInvariant();
        $this->assertNoNegativeBalances();
        $this->assertSame([], app(StripeReconciliation::class)->report()['mismatches']);
    }

    public function test_a_weekly_refund_request_is_filed_by_the_merchant_login_and_left_for_a_person(): void
    {
        // The week of Nov 9: run each day until the refund slot has run.
        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'] as $date) {
            $this->runDay($date);
        }

        $action = DemoActivityAction::where('kind', DemoActivityAction::KIND_REFUND_REQUEST)->firstOrFail();
        $this->assertSame(DemoActivityAction::STATUS_DONE, $action->status, (string) $action->note);
        $correction = $action->correction;
        $this->assertSame(Correction::STATUS_REQUESTED, $correction->status);
        $this->assertSame('merchant', $correction->requested_by_role);
        $this->assertTrue($correction->redemption->isGeneratedDemoActivity());
        $this->assertSame(1, DemoActivityAction::where('kind', DemoActivityAction::KIND_RECOGNITION)->where('status', DemoActivityAction::STATUS_DONE)->count());
        $this->assertLedgerInvariant();
    }
}
