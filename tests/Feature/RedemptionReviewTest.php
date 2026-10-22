<?php

namespace Tests\Feature;

use App\Enums\DenialReason;
use App\Enums\Role;
use App\Exceptions\RedemptionAlreadyReviewed;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\RedemptionDecision;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\RedemptionReviewService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

class RedemptionReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->reviewer = User::factory()->role(Role::BidAdmin)->create(['name' => 'Sam Taylor']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Redeems $20 at the deli and has the fake AI hold it. */
    private function heldRedemption(): Redemption
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(91, 'hold', 'Round number.'));
        $deli = Merchant::where('name', 'Press Room Deli')->firstOrFail();
        $this->signIn(Role::Employee);
        $this->post('/employee/redeem', ['merchant_id' => $deli->id, 'amount' => '20']);
        $this->signIn(Role::BidAdmin);

        $redemption = Redemption::latest('id')->firstOrFail();
        $this->assertTrue($redemption->isUnderReview());

        return $redemption;
    }

    private function review(): RedemptionReviewService
    {
        return app(RedemptionReviewService::class);
    }

    public function test_approving_spends_the_reserved_credits_and_records_who_and_when(): void
    {
        $employee = Employee::firstOrFail();
        $before = $employee->balanceCents();
        $redemption = $this->heldRedemption();
        $this->assertSame($before - 2000, $employee->balanceCents(), 'Held credits are reserved.');

        Carbon::setTestNow('2026-10-12 15:04:00');
        $this->review()->approve($redemption, $this->reviewer, 'Regular customer, receipt checked.');

        $redemption->refresh();
        $this->assertSame(Redemption::STATUS_APPROVED, $redemption->status);
        $this->assertSame($this->reviewer->name, $redemption->reviewed_by);
        $this->assertTrue($redemption->reviewed_at->eq('2026-10-12 15:04:00'));

        $entry = CreditLedgerEntry::where('redemption_id', $redemption->id)->where('type', CreditLedgerEntry::TYPE_REVIEW_APPROVED)->sole();
        $this->assertSame(0, $entry->amount_cents);
        $this->assertSame($this->reviewer->name, $entry->decided_by);
        $this->assertSame('2026-10-12 15:04:00', $entry->created_at->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('Approved by Sam Taylor:', $entry->description);

        $this->assertSame($before - 2000, $employee->balanceCents(), 'Approval spends the reserved credits; nothing comes back.');

        $decision = RedemptionDecision::where('redemption_id', $redemption->id)->sole();
        $this->assertSame(RedemptionDecision::APPROVE, $decision->decision);
        $this->assertSame('Regular customer, receipt checked.', $decision->reason);
        $this->assertSame($this->reviewer->id, $decision->decided_by_user_id);
        $this->assertSame('Sam Taylor', $decision->decided_by_name);
        $this->assertSame('bid_admin', $decision->decided_by_role);
        $this->assertTrue($decision->decided_at->eq('2026-10-12 15:04:00'));
        $this->assertSame(['hold', 91, $redemption->scored_by], [$decision->ai_decision, $decision->ai_risk_score, $decision->ai_scored_by], 'The AI\'s original answer is kept with the decision');
        $this->assertNull($decision->credit_returned_to);
    }

    public function test_denying_as_no_purchase_returns_the_reserved_credits_to_the_employee(): void
    {
        $employee = Employee::firstOrFail();
        $before = $employee->balanceCents();
        $redemption = $this->heldRedemption();

        Carbon::setTestNow('2026-10-12 15:10:00');
        $this->review()->deny($redemption, $this->reviewer, 'Merchant says no purchase happened.', DenialReason::NoPurchase);

        $redemption->refresh();
        $this->assertSame(Redemption::STATUS_REJECTED, $redemption->status);
        $this->assertSame($this->reviewer->name, $redemption->reviewed_by);
        $this->assertTrue($redemption->reviewed_at->eq('2026-10-12 15:10:00'));

        $entry = CreditLedgerEntry::where('redemption_id', $redemption->id)->where('type', CreditLedgerEntry::TYPE_REVIEW_REJECTED)->sole();
        $this->assertSame(2000, $entry->amount_cents);
        $this->assertSame($this->reviewer->name, $entry->decided_by);
        $this->assertSame('2026-10-12 15:10:00', $entry->created_at->format('Y-m-d H:i:s'));

        $this->assertSame($before, $employee->balanceCents(), 'Rejection gives the full amount back.');

        // The original debit is still there: the ledger only ever grows.
        $this->assertSame(1, CreditLedgerEntry::where('redemption_id', $redemption->id)->where('type', CreditLedgerEntry::TYPE_REDEMPTION)->count());
        $this->assertSame('employee', RedemptionDecision::where('redemption_id', $redemption->id)->sole()->credit_returned_to);
    }

    public function test_denying_as_suspected_abuse_returns_the_credits_to_the_employer_not_the_employee(): void
    {
        $employee = Employee::firstOrFail();
        $employer = $employee->employer;
        $before = $employee->balanceCents();
        $employerBefore = $employer->balanceCents();
        $redemption = $this->heldRedemption();

        $this->review()->deny($redemption, $this->reviewer, 'Same round amount every week.', DenialReason::SuspectedAbuse);

        $this->assertSame($before - 2000, $employee->balanceCents(), 'A suspected colluder does not get the credit back to try again');
        $this->assertSame($employerBefore + 2000, $employer->balanceCents(), 'It goes back to the employer\'s paid pool');
        $entry = CreditLedgerEntry::where('redemption_id', $redemption->id)->where('type', CreditLedgerEntry::TYPE_REVIEW_DENIED_TO_EMPLOYER)->sole();
        $this->assertSame($employer->id, $entry->employer_id);
        $this->assertNull($entry->employee_id);
        $this->assertSame('employer', RedemptionDecision::where('redemption_id', $redemption->id)->sole()->credit_returned_to);
        $this->assertSame(0, CreditLedgerEntry::where('redemption_id', $redemption->id)->where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)->count());
    }

    public function test_decisions_are_append_only_and_need_a_person(): void
    {
        $redemption = $this->heldRedemption();
        $this->review()->approve($redemption, $this->reviewer, 'Checked with the merchant.');
        $decision = RedemptionDecision::sole();

        foreach ([fn () => $decision->update(['reason' => 'Edited.']), fn () => $decision->delete()] as $change) {
            try {
                $change();
                $this->fail('Decisions are append-only.');
            } catch (\LogicException) {
            }
        }

        $this->expectException(\LogicException::class);
        RedemptionDecision::create(['redemption_id' => $redemption->id, 'decision' => 'approve', 'reason' => 'By a judge.',
            'decided_by_user_id' => User::factory()->role(Role::Judge)->create()->id, 'decided_by_name' => 'X', 'decided_at' => now()]);
    }

    public static function secondDecisions(): array
    {
        return [
            'approve then approve' => ['approve', 'approve'],
            'approve then deny' => ['approve', 'deny'],
            'deny then deny' => ['deny', 'deny'],
            'deny then approve' => ['deny', 'approve'],
        ];
    }

    #[DataProvider('secondDecisions')]
    public function test_a_redemption_cannot_be_decided_twice(string $first, string $second): void
    {
        $employee = Employee::firstOrFail();
        $redemption = $this->heldRedemption();
        $this->decide($first, $redemption, $this->reviewer);
        $status = $redemption->fresh()->status;
        $entries = CreditLedgerEntry::count();
        $balance = $employee->balanceCents();

        try {
            // A stale copy, as if a second tab still showed it under review.
            $this->decide($second, Redemption::find($redemption->id)->setAttribute('status', Redemption::STATUS_UNDER_REVIEW), User::factory()->role(Role::PlatformAdmin)->create());
            $this->fail('A second decision should be refused.');
        } catch (RedemptionAlreadyReviewed) {
        }

        $this->assertSame($status, $redemption->fresh()->status);
        $this->assertSame($this->reviewer->name, $redemption->fresh()->reviewed_by);
        $this->assertSame($entries, CreditLedgerEntry::count());
        $this->assertSame($balance, $employee->balanceCents());
        $this->assertSame(1, RedemptionDecision::where('redemption_id', $redemption->id)->count());
    }

    private function decide(string $action, Redemption $redemption, User $reviewer): void
    {
        $action === 'approve'
            ? $this->review()->approve($redemption, $reviewer, 'Checked with the merchant.')
            : $this->review()->deny($redemption, $reviewer, 'No purchase happened.', DenialReason::NoPurchase);
    }

    public function test_a_redemption_the_ai_cleared_cannot_be_reviewed(): void
    {
        $approved = Redemption::where('status', Redemption::STATUS_APPROVED)->firstOrFail();
        $entries = CreditLedgerEntry::count();

        $this->expectException(RedemptionAlreadyReviewed::class);

        try {
            $this->review()->deny($approved, $this->reviewer, 'Trying to deny a cleared one.', DenialReason::SuspectedAbuse);
        } finally {
            $this->assertSame($entries, CreditLedgerEntry::count());
            $this->assertSame(Redemption::STATUS_APPROVED, $approved->fresh()->status);
        }
    }

    public function test_held_redemptions_show_approve_and_deny_with_a_reason_and_others_do_not(): void
    {
        $held = $this->heldRedemption();
        $cleared = Redemption::where('status', Redemption::STATUS_APPROVED)->firstOrFail();

        $this->get('/bid-admin')
            ->assertOk()
            ->assertSee('Waiting for your decision.')
            ->assertSee('action="'.route('bid-admin.approve', $held).'"', false)
            ->assertSee('formaction="'.route('bid-admin.deny', $held).'"', false)
            ->assertSee('name="reason"', false)
            ->assertSee('Suspected abuse: credit goes back to the employer')
            ->assertSee('aria-label="Approve $20.00 at Press Room Deli"', false)
            ->assertDontSee('action="'.route('bid-admin.approve', $cleared).'"', false)
            ->assertDontSee('formaction="'.route('bid-admin.deny', $cleared).'"', false);
    }

    public function test_approve_button_approves_and_shows_who_decided(): void
    {
        $held = $this->heldRedemption();
        Carbon::setTestNow(Carbon::parse('2026-10-12 15:04', 'America/New_York'));

        $this->post(route('bid-admin.approve', $held), ['reason' => 'Regular customer.'])
            ->assertRedirect(route('bid-admin.dashboard')."#redemption-{$held->id}")
            ->assertSessionHas('status', 'Approved $20.00 at Press Room Deli for J.R. Now owed to the merchant (simulated, no money moved).');

        $this->assertSame(Redemption::STATUS_APPROVED, $held->fresh()->status);
        $this->assertSame('Sam Taylor', $held->fresh()->reviewed_by);
        $this->assertSame($this->reviewer->id, $held->fresh()->reviewed_by_user_id);

        $this->get('/bid-admin')
            ->assertSee('Final decision:')
            ->assertSee('approved by Sam Taylor on Oct 12, 3:04 PM')
            ->assertSee('Regular customer.')
            ->assertSee('AI held (risk 91) → approved by BID admin S.T. · Oct 12 → owed to the merchant, simulated (no Stripe transfer)')
            ->assertDontSee('Waiting for your decision.');
    }

    public function test_a_decision_without_a_reason_or_deny_without_a_where_changes_nothing(): void
    {
        $held = $this->heldRedemption();
        $entries = CreditLedgerEntry::count();

        $this->post(route('bid-admin.approve', $held), ['reason' => ''])->assertSessionHasErrors('reason', null, "review{$held->id}");
        $this->post(route('bid-admin.approve', $held), ['reason' => 'ok'])->assertSessionHasErrors('reason', null, "review{$held->id}");
        $this->post(route('bid-admin.deny', $held), ['reason' => 'Looks like collusion.'])->assertSessionHasErrors('denial', null, "review{$held->id}");
        $this->post(route('bid-admin.deny', $held), ['reason' => 'Looks like collusion.', 'denial' => 'refund'])->assertSessionHasErrors('denial', null, "review{$held->id}");

        $this->assertTrue($held->fresh()->isUnderReview());
        $this->assertSame($entries, CreditLedgerEntry::count());
        $this->assertSame(0, RedemptionDecision::count());
    }

    public function test_deny_button_denies_and_the_employee_gets_the_credits_back(): void
    {
        $employee = Employee::firstOrFail();
        $before = $employee->balanceCents();
        $held = $this->heldRedemption();

        $this->post(route('bid-admin.deny', $held), ['reason' => 'Tapped by mistake.', 'denial' => 'no_purchase'])
            ->assertSessionHas('status', 'Denied $20.00 at Press Room Deli for J.R. No transfer; the credit is back with the employee.');

        $this->assertSame(Redemption::STATUS_REJECTED, $held->fresh()->status);
        $this->assertSame($before, $employee->balanceCents());

        $this->get('/bid-admin')->assertSee('denied by Sam Taylor on')->assertSee('Denied: the credits went back to the employee.')
            ->assertSee('denied by BID admin S.T.');
        $this->signIn(Role::Employee);
        $this->get('/employee')->assertSee('Denied')->assertSee('Credit back in your balance');
    }

    public function test_a_second_click_is_refused_and_changes_nothing(): void
    {
        $held = $this->heldRedemption();
        $this->post(route('bid-admin.approve', $held), ['reason' => 'Regular customer.']);
        $entries = CreditLedgerEntry::count();

        $this->post(route('bid-admin.deny', $held), ['reason' => 'Second tab.', 'denial' => 'no_purchase'])
            ->assertRedirect(route('bid-admin.dashboard')."#redemption-{$held->id}")
            ->assertSessionHas('error', 'Nothing changed: this redemption is already approved.');
        $this->post(route('bid-admin.approve', $held), ['reason' => 'Double click.'])
            ->assertSessionHas('error', 'Nothing changed: this redemption is already approved.');
        $this->assertSame(1, RedemptionDecision::count());

        $this->assertSame(Redemption::STATUS_APPROVED, $held->fresh()->status);
        $this->assertSame($entries, CreditLedgerEntry::count());
    }

    public static function rolesThatCannotDecide(): array
    {
        return [
            'judge' => [Role::Judge],
            'employee' => [Role::Employee],
            'employer admin' => [Role::EmployerAdmin],
            'merchant' => [Role::Merchant],
        ];
    }

    #[DataProvider('rolesThatCannotDecide')]
    public function test_only_bid_admins_and_the_platform_admin_can_approve_or_deny(Role $role): void
    {
        $held = $this->heldRedemption();
        $entries = CreditLedgerEntry::count();

        $this->signIn($role);
        $this->post(route('bid-admin.approve', $held), ['reason' => 'Looks fine to me.'])->assertForbidden();
        $this->post(route('bid-admin.deny', $held), ['reason' => 'Looks wrong to me.', 'denial' => 'no_purchase'])->assertForbidden();

        $this->assertTrue($held->fresh()->isUnderReview());
        $this->assertSame(0, RedemptionDecision::count());
        $this->assertNull($held->fresh()->reviewed_by);
        $this->assertSame($entries, CreditLedgerEntry::count());
    }

    public function test_judges_get_403_on_approve_and_deny_even_with_view_access(): void
    {
        $held = $this->heldRedemption();
        $judge = User::factory()->role(Role::Judge)->create();

        // The policy itself refuses judges, not only the route's role check.
        $this->assertFalse($judge->can('review', $held));
        $this->actingAs($judge)->post(route('bid-admin.approve', $held), ['reason' => 'Looks fine to me.'])->assertForbidden();
        $this->actingAs($judge)->post(route('bid-admin.deny', $held), ['reason' => 'Looks wrong.', 'denial' => 'no_purchase'])->assertForbidden();
        $this->assertTrue($held->fresh()->isUnderReview());
    }

    public function test_the_platform_admin_can_decide_and_is_recorded(): void
    {
        $held = $this->heldRedemption();
        $admin = $this->signIn(Role::PlatformAdmin);

        $this->post(route('bid-admin.deny', $held), ['reason' => 'Tapped by mistake.', 'denial' => 'no_purchase'])->assertSessionHas('status');

        $this->assertSame($admin->name, $held->fresh()->reviewed_by);
        $this->assertSame($admin->id, $held->fresh()->reviewed_by_user_id);
        $this->assertSame($admin->name, CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_REVIEW_REJECTED)->sole()->decided_by);
    }

    public function test_guests_cannot_approve_or_deny(): void
    {
        $held = $this->heldRedemption();
        auth()->logout();

        $this->post(route('bid-admin.approve', $held))->assertRedirect('/login');
        $this->assertTrue($held->fresh()->isUnderReview());
    }
}
