<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\Fraud\SignalCalculator;
use App\Services\Sandbox\Sandbox;
use App\Services\Sandbox\SandboxFiles;
use App\Services\Solvency\SolvencyCheck;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesFakeStripe;
use Tests\Concerns\UsesSandboxes;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The whole "Try this" checklist through the real app inside a sandbox
 * (ADR 0045), while watching the live (main) database connection: any write
 * to it fails the test. Stripe keys are set and a fake gateway is bound, so a
 * single Stripe call would also show.
 */
class SandboxWalkthroughTest extends TestCase
{
    use RefreshDatabase, UsesFakeStripe, UsesSandboxes;

    /** @var list<string> */
    private array $mainWrites = [];

    private FakeClaudeClient $claude;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSandboxes();
        $this->fakeStripe();
        // Holds round amounts of $40 or more, clears the rest; records what it was sent.
        $this->claude = new FakeClaudeClient(function (string $input) {
            $amount = json_decode($input, true)['amount_cents'] ?? 0;

            return json_encode($amount >= 4000
                ? ['risk_score' => 85, 'decision' => 'hold', 'explanation' => 'Round amount well above usual.']
                : ['risk_score' => 10, 'decision' => 'clear', 'explanation' => 'Looks like a normal purchase.']);
        });
        $this->app->instance(ClaudeClient::class, $this->claude);
    }

    private function watchMainConnection(): void
    {
        $main = DB::getDefaultConnection();
        DB::listen(function (QueryExecuted $query) use ($main) {
            if ($query->connectionName === Sandbox::CONNECTION) {
                return;
            }
            if (! preg_match('/^\s*select\b/i', $query->sql)) {
                $this->mainWrites[] = "[{$query->connectionName}] {$query->sql}";
            }
            $this->assertSame($main, $query->connectionName, 'Only the live connection and the sandbox are ever used');
        });
    }

    public function test_the_checklist_moves_money_inside_the_sandbox_and_never_writes_the_live_database_or_calls_stripe(): void
    {
        $this->watchMainConnection();

        // Start: lands on the checklist as the employer, with the banner and role bar.
        $this->startSandbox();
        $this->sandboxGet(route('sandbox.home'))->assertOk()
            ->assertSee('Sandbox: your own copy. Nothing here touches the live demo or Stripe. Deleted after 24 hours.')
            ->assertSee('Try This')->assertSee('Switch to </span>Employer (HR)', false)
            ->assertSee('See what the merchant is owed (payouts are grouped and paid later; in the sandbox they stay simulated)');

        // 1. Buy credits, simulated, with the label.
        $this->sandboxGet(route('employer.dashboard'))->assertOk()
            ->assertSee('Simulated payment: no card is charged and Stripe is not contacted.');
        $paidBefore = $this->insideSandbox(fn () => CreditLedgerEntry::where('type', 'purchase')->sum('amount_cents'));
        $this->sandboxPost(route('employer.purchases.store'), ['purchase_amount' => '500'])->assertSessionHasNoErrors();
        $this->assertSame(50000, (int) $this->insideSandbox(fn () => CreditLedgerEntry::where('type', 'purchase')->sum('amount_cents')) - (int) $paidBefore);

        // 2. Give credits to the employee the sandbox employee login runs.
        $employeeId = $this->insideSandbox(fn () => Employee::whereHas('user', fn ($q) => $q->where('email', 'employee@sandbox.invalid'))->value('id'));
        $balance = fn () => $this->insideSandbox(fn () => Employee::find($employeeId)->balanceCents());
        $before = $balance();
        $this->sandboxPost(route('employer.allocations.store'), ['mode' => 'individual', 'occasion' => 'recognition', 'amount' => '100', 'employee_id' => $employeeId])
            ->assertSessionHasNoErrors();
        $this->assertSame($before + 10000, $balance());

        // 3. Pay at a merchant as the employee: the fraud check clears it.
        $this->switchRole('employee')->assertRedirect(route('employee.wallet'));
        $grind = $this->insideSandbox(fn () => Merchant::where('name', 'Farragut Grind')->value('id'));
        $this->sandboxPost(route('employee.redeem'), ['merchant_id' => $grind, 'amount' => '12.50'])->assertSessionHasNoErrors();
        $cleared = $this->insideSandbox(fn () => Redemption::orderByDesc('id')->first());
        $this->assertTrue($cleared->isApproved());

        // 4. The merchant sees it, with its simulated payout.
        $this->switchRole('merchant');
        $this->sandboxGet(route('merchant.dashboard'))->assertOk()->assertSee('$12.50');

        // 5. The BID admin sees the impact.
        $this->switchRole('bid_admin');
        $this->sandboxGet(route('bid-admin.impact'))->assertOk();

        // 6. A suspicious payment is held, and 7. the Local Goods reviewer approves it.
        $this->switchRole('employee');
        $this->sandboxPost(route('employee.redeem'), ['merchant_id' => $grind, 'amount' => '60'])->assertSessionHasNoErrors();
        $held = $this->insideSandbox(fn () => Redemption::orderByDesc('id')->first());
        $this->assertSame(Redemption::STATUS_UNDER_REVIEW, $held->status);

        $this->switchRole('reviewer')->assertRedirect(route('bid-admin.dashboard'));
        $this->sandboxGet(route('bid-admin.dashboard'))->assertOk();
        $this->sandboxPost(route('bid-admin.approve', $held->id), ['reason' => 'Checked with the merchant.'])->assertSessionHasNoErrors();
        $this->assertTrue($this->insideSandbox(fn () => Redemption::find($held->id)->isApproved()));
        $this->sandboxGet(route('money'))->assertOk();

        // The sandbox's books still balance.
        $this->assertTrue($this->insideSandbox(fn () => app(SolvencyCheck::class)->now()->isBalanced()));

        // Start over: a fresh copy, the old file gone.
        $first = $this->sandboxId();
        $this->sandboxPost(route('sandbox.start'))->assertRedirect(route('sandbox.home'));
        $this->assertNotSame($first, $this->sandboxId());
        $this->assertFileDoesNotExist($this->sandboxDir."/sb-{$first}.sqlite");

        // Exit: the file is deleted and the cookie cleared.
        $last = $this->sandboxId();
        $this->sandboxPost(route('sandbox.exit'))->assertRedirect(route('home'));
        $this->assertFileDoesNotExist($this->sandboxDir."/sb-{$last}.sqlite");
        $this->assertArrayNotHasKey(Sandbox::COOKIE, $this->sandboxJar);

        $this->assertSame([], $this->mainWrites, 'A sandbox request wrote to the live database');
        // Control: the listener does see a write to the live database.
        DB::table('feedback')->where('id', -1)->delete();
        $this->assertCount(1, $this->mainWrites, 'The watch on the live connection works');
        $this->assertSame([], $this->stripe->calls ?? [], 'A sandbox request called Stripe');
        $this->assertCount(2, $this->claude->calls, 'Both payments were checked by the AI');
        $this->assertFalse(Sandbox::active());
    }

    public function test_the_ai_gets_the_frozen_prompt_and_the_same_signals_the_live_code_computes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:30', 'America/New_York'));
        copy(self::template(), app(SandboxFiles::class)->templatePath());
        $this->startSandbox();
        $this->switchRole('employee');
        [$employee, $grind] = $this->insideSandbox(fn () => [
            Employee::whereHas('user', fn ($q) => $q->where('email', 'employee@sandbox.invalid'))->firstOrFail(),
            Merchant::where('name', 'Farragut Grind')->firstOrFail(),
        ]);
        // What the unchanged live signal code computes for this payment, inside the same copy.
        $expected = $this->insideSandbox(fn () => app(SignalCalculator::class)->calculate($employee->fresh(), $grind->fresh(), 900));

        $this->sandboxPost(route('employee.redeem'), ['merchant_id' => $grind->id, 'amount' => '9.00'])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->claude->calls);
        $this->assertSame(file_get_contents(resource_path('prompts/redemption-fraud-detection.md')), $this->claude->calls[0]['system']);
        $this->assertSame(json_encode($expected, JSON_PRETTY_PRINT), $this->claude->calls[0]['user']);
    }
}
