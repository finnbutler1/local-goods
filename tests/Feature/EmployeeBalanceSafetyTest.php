<?php

namespace Tests\Feature;

use App\Models\Correction;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\Corrections\CorrectionLimits;
use App\Services\CreditLedger;
use App\Services\PayCodes;
use App\Services\RedemptionBasket;
use App\Services\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\RunsDemoActivity;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Employees can't spend more than their balance. The tests behind the
 * "Money" line on /security: the check includes DC sales tax (at issue and
 * at confirm), two pay codes made at the same moment can't both spend the
 * same credits, a payment held for review keeps its credits set aside, the
 * ledger layer's refund does what it is told while the cap sits in
 * CorrectionLimits, and a seeded demo with a burst of generator activity and
 * refunds never leaves a balance below zero or different from its ledger.
 *
 * SQLite can't run two requests at the same instant, so the Gap 4 race is
 * simulated by making the second code live again after it was replaced, as a
 * request that didn't see the first would have left it. The real two-connection
 * race on MySQL is a Dec 1 reminder in CLAUDE.md.
 */
class EmployeeBalanceSafetyTest extends TestCase
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

    private function codes(): PayCodes
    {
        return app(PayCodes::class);
    }

    private function jordan(): Employee
    {
        return Employee::orderBy('id')->firstOrFail();
    }

    private function merchant(string $name): Merchant
    {
        return Merchant::where('name', $name)->firstOrFail();
    }

    /** Leaves exactly this balance: tops up from the employer's credits or spends the difference at a shop. */
    private function setBalance(Employee $employee, int $cents): void
    {
        $now = $employee->fresh()->balanceCents();
        if ($now < $cents) {
            app(CreditLedger::class)->allocate(Employer::firstOrFail(), $employee, $cents - $now);
        } elseif ($now > $cents) {
            app(RedemptionService::class)->redeem($employee->fresh(), $this->merchant('Press Room Deli'), $now - $cents);
        }
        $this->assertSame($cents, $employee->fresh()->balanceCents());
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        }

        $this->fail('Expected the payment to be refused.');
    }

    // Gap 5: tax counts toward the balance.

    public function test_a_pay_code_whose_total_with_tax_is_over_the_balance_is_refused_at_issue_and_at_confirm(): void
    {
        $cycle = $this->merchant('Lafayette Cycle Studio');
        $basket = RedemptionBasket::build($cycle, [$cycle->products()->where('name', 'Drop-in class')->value('id') => 1], 0);
        $this->assertGreaterThan(0, $basket['tax']->taxCents, 'The order must have sales tax added for this test to mean anything.');

        // The balance covers the menu price but not the price plus tax.
        $employee = $this->jordan();
        $this->setBalance($employee, $basket['tax']->subtotalCents);
        $this->assertLessThan($basket['total_cents'], $employee->fresh()->balanceCents());

        $entries = CreditLedgerEntry::count();
        $redemptions = Redemption::count();

        $at = $this->refusal(fn () => $this->codes()->issue($employee->fresh(), $cycle, $basket['total_cents'], $basket['items'], $basket['tax']));
        $this->assertSame('That is more than the available balance.', $at);
        $this->assertSame(0, PayCode::where('employee_id', $employee->id)->count());

        // A code made while it was affordable, then the balance falls short of the taxed total before the shop confirms.
        $this->setBalance($employee, $basket['total_cents']);
        $payCode = $this->codes()->issue($employee->fresh(), $cycle, $basket['total_cents'], $basket['items'], $basket['tax']);
        $this->setBalance($employee, $basket['total_cents'] - 1);
        $entriesBefore = CreditLedgerEntry::count();
        $redemptionsBefore = Redemption::count();

        $this->assertSame(PayCodes::NOT_ENOUGH_CREDITS, $this->refusal(fn () => $this->codes()->confirm($payCode, $cycle)));
        $this->assertSame($basket['total_cents'] - 1, $employee->fresh()->balanceCents());
        $this->assertSame($entriesBefore, CreditLedgerEntry::count());
        $this->assertSame($redemptionsBefore, Redemption::count());
        $this->assertSame(PayCode::BY_BALANCE, $payCode->fresh()->cancelled_by);
        $this->assertGreaterThan($entries, $entriesBefore, 'Only the set-up spending above wrote entries.');
        $this->assertGreaterThan($redemptions, $redemptionsBefore);
    }

    // Gap 4: two codes live at once.

    public function test_two_pay_codes_live_at_once_can_not_spend_the_same_credits(): void
    {
        $grind = $this->merchant('Farragut Grind');
        $deli = $this->merchant('Press Room Deli');
        $employee = $this->jordan();
        $this->setBalance($employee, 2000);

        $a = $this->codes()->issue($employee->fresh(), $grind, 1500);
        $b = $this->codes()->issue($employee->fresh(), $deli, 1500);
        // The race: the second request never saw the first, so both are live.
        PayCode::whereKey($a->id)->update(['status' => PayCode::STATUS_ACTIVE, 'cancelled_by' => null, 'cancelled_at' => null]);
        $this->assertSame(2, PayCode::where('employee_id', $employee->id)->where('status', PayCode::STATUS_ACTIVE)->count());

        $this->codes()->confirm($a->fresh(), $grind);
        $this->assertSame(500, $employee->fresh()->balanceCents());

        $this->assertSame(PayCodes::NOT_ENOUGH_CREDITS, $this->refusal(fn () => $this->codes()->confirm($b->fresh(), $deli)));

        $this->assertSame(500, $employee->fresh()->balanceCents(), 'Total spent is 1500 of 2000, never more');
        $this->assertSame(1, Redemption::where('employee_id', $employee->id)->where('channel', Redemption::CHANNEL_IN_PERSON)->where('amount_cents', 1500)->count());
        $this->assertSame(PayCode::BY_BALANCE, $b->fresh()->cancelled_by);
    }

    public function test_a_payment_held_for_review_keeps_its_credits_set_aside(): void
    {
        $grind = $this->merchant('Farragut Grind');
        $deli = $this->merchant('Press Room Deli');
        $employee = $this->jordan();
        $this->setBalance($employee, 2000);

        $a = $this->codes()->issue($employee->fresh(), $grind, 1500);
        $b = $this->codes()->issue($employee->fresh(), $deli, 1500);
        PayCode::whereKey($a->id)->update(['status' => PayCode::STATUS_ACTIVE, 'cancelled_by' => null, 'cancelled_at' => null]);

        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(88, 'hold', 'Unusual amount.'));
        $held = $this->codes()->confirm($a->fresh(), $grind);

        $this->assertSame(Redemption::STATUS_UNDER_REVIEW, $held->status);
        $this->assertSame(500, $employee->fresh()->balanceCents(), 'The held 1500 is out of the balance while a person decides');
        $this->assertSame(PayCodes::NOT_ENOUGH_CREDITS, $this->refusal(fn () => $this->codes()->confirm($b->fresh(), $deli)));
        $this->assertSame(500, $employee->fresh()->balanceCents());
    }

    // Gap 6: the refund at the ledger layer.

    public function test_the_ledger_refund_writes_what_it_is_told_and_the_cap_is_enforced_by_correction_limits(): void
    {
        // The real path first: it refuses an over-refund before anything is written.
        $redemption = $this->cleared(1250);
        $request = $this->refundRequest($redemption);
        $balance = $redemption->employee->fresh()->balanceCents();
        $entries = CreditLedgerEntry::count();

        $this->assertSame(1250, CorrectionLimits::redemptionLeftCents($redemption));
        try {
            app(CorrectionDecisions::class)->approveRedemptionRefund($request, $this->admin(), 'Too much.', amountCents: 5000);
            $this->fail('A refund over what was spent must be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('can still be refunded', $e->getMessage());
        }
        $this->assertSame($balance, $redemption->employee->fresh()->balanceCents());
        $this->assertSame($entries, CreditLedgerEntry::count());

        // Documented as it is: CreditLedger::refundRedemption only requires a positive amount.
        // It does not compare with what was spent, so a caller that skips CorrectionLimits could over-refund.
        // Last, because it deliberately leaves this test's ledger out of balance.
        $second = $this->cleared(1250);
        $before = $second->employee->fresh()->balanceCents();
        app(CreditLedger::class)->refundRedemption($this->refundRequest($second), 5000);
        $this->assertSame($before + 5000, $second->employee->fresh()->balanceCents());
    }

    // Gap 2: a busy demo.

    public function test_after_the_seed_a_burst_of_activity_and_refunds_no_balance_is_negative_and_each_equals_its_ledger(): void
    {
        // Three simulated sales (made before any Stripe-paid purchase exists) to refund later.
        $targets = collect([1000, 1100, 1200])->map(fn ($cents) => $this->cleared($cents));

        $this->runDay('2026-10-26');
        $this->runDay('2026-10-27');

        // Refunds through the real decision service.
        foreach ($targets as $redemption) {
            $request = $this->refundRequest($redemption);
            $done = app(CorrectionDecisions::class)->approveRedemptionRefund($request, $this->admin(), 'Test refund.', amountCents: 300);
            $this->assertSame(Correction::STATUS_COMPLETED, $done->status);
        }

        $this->assertNoNegativeBalances();
        $table = (new CreditLedgerEntry)->getTable();
        foreach (Employee::orderBy('id')->get() as $employee) {
            $this->assertSame(
                (int) DB::table($table)->where('employee_id', $employee->id)->sum('amount_cents'),
                $employee->balanceCents(),
                $employee->initials(),
            );
        }
        $this->assertLedgerInvariant();
    }
}
