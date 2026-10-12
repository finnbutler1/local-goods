<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\RedemptionAlreadyReviewed;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
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
        $this->review()->approve($redemption, $this->reviewer);

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
    }

    public function test_rejecting_returns_the_reserved_credits_and_records_who_and_when(): void
    {
        $employee = Employee::firstOrFail();
        $before = $employee->balanceCents();
        $redemption = $this->heldRedemption();

        Carbon::setTestNow('2026-10-12 15:10:00');
        $this->review()->reject($redemption, $this->reviewer);

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
    }

    public static function secondDecisions(): array
    {
        return [
            'approve then approve' => ['approve', 'approve'],
            'approve then reject' => ['approve', 'reject'],
            'reject then reject' => ['reject', 'reject'],
            'reject then approve' => ['reject', 'approve'],
        ];
    }

    #[DataProvider('secondDecisions')]
    public function test_a_redemption_cannot_be_decided_twice(string $first, string $second): void
    {
        $employee = Employee::firstOrFail();
        $redemption = $this->heldRedemption();
        $this->review()->{$first}($redemption, $this->reviewer);
        $status = $redemption->fresh()->status;
        $entries = CreditLedgerEntry::count();
        $balance = $employee->balanceCents();

        try {
            // A stale copy, as if a second tab still showed it under review.
            $this->review()->{$second}(Redemption::find($redemption->id)->setAttribute('status', Redemption::STATUS_UNDER_REVIEW), User::factory()->role(Role::PlatformAdmin)->create());
            $this->fail('A second decision should be refused.');
        } catch (RedemptionAlreadyReviewed) {
        }

        $this->assertSame($status, $redemption->fresh()->status);
        $this->assertSame($this->reviewer->name, $redemption->fresh()->reviewed_by);
        $this->assertSame($entries, CreditLedgerEntry::count());
        $this->assertSame($balance, $employee->balanceCents());
    }

    public function test_a_redemption_the_ai_cleared_cannot_be_reviewed(): void
    {
        $approved = Redemption::where('status', Redemption::STATUS_APPROVED)->firstOrFail();
        $entries = CreditLedgerEntry::count();

        $this->expectException(RedemptionAlreadyReviewed::class);

        try {
            $this->review()->reject($approved, $this->reviewer);
        } finally {
            $this->assertSame($entries, CreditLedgerEntry::count());
            $this->assertSame(Redemption::STATUS_APPROVED, $approved->fresh()->status);
        }
    }

    public function test_held_redemptions_show_approve_and_reject_buttons_and_others_do_not(): void
    {
        $held = $this->heldRedemption();
        $cleared = Redemption::where('status', Redemption::STATUS_APPROVED)->firstOrFail();

        $this->get('/bid-admin')
            ->assertOk()
            ->assertSee('Waiting for your decision.')
            ->assertSee('action="'.route('bid-admin.approve', $held).'"', false)
            ->assertSee('action="'.route('bid-admin.reject', $held).'"', false)
            ->assertSee('aria-label="Approve $20.00 at Press Room Deli"', false)
            ->assertDontSee('action="'.route('bid-admin.approve', $cleared).'"', false)
            ->assertDontSee('action="'.route('bid-admin.reject', $cleared).'"', false);
    }

    public function test_approve_button_approves_and_shows_who_decided(): void
    {
        $held = $this->heldRedemption();
        Carbon::setTestNow(Carbon::parse('2026-10-12 15:04', 'America/New_York'));

        $this->post(route('bid-admin.approve', $held))
            ->assertRedirect(route('bid-admin.dashboard')."#redemption-{$held->id}")
            ->assertSessionHas('status', 'Approved $20.00 at Press Room Deli. The reserved credits are spent.');

        $this->assertSame(Redemption::STATUS_APPROVED, $held->fresh()->status);
        $this->assertSame('Sam Taylor', $held->fresh()->reviewed_by);
        $this->assertSame($this->reviewer->id, $held->fresh()->reviewed_by_user_id);

        $this->get('/bid-admin')
            ->assertSee('Approved by')
            ->assertSee('<span class="font-semibold text-ink">Sam Taylor</span>', false)
            ->assertSee('Oct 12, 3:04 PM')
            ->assertDontSee('Waiting for your decision.');
    }

    public function test_reject_button_rejects_and_the_employee_gets_the_credits_back(): void
    {
        $employee = Employee::firstOrFail();
        $before = $employee->balanceCents();
        $held = $this->heldRedemption();

        $this->post(route('bid-admin.reject', $held))
            ->assertSessionHas('status', "Rejected \$20.00 at Press Room Deli. The credits are back in the employee's balance.");

        $this->assertSame(Redemption::STATUS_REJECTED, $held->fresh()->status);
        $this->assertSame($before, $employee->balanceCents());

        $this->get('/bid-admin')->assertSee('Rejected by')->assertSee('The credits went back to the employee.');
        $this->signIn(Role::Employee);
        $this->get('/employee')->assertSee('Rejected');
    }

    public function test_a_second_click_is_refused_and_changes_nothing(): void
    {
        $held = $this->heldRedemption();
        $this->post(route('bid-admin.approve', $held));
        $entries = CreditLedgerEntry::count();

        $this->post(route('bid-admin.reject', $held))
            ->assertRedirect(route('bid-admin.dashboard')."#redemption-{$held->id}")
            ->assertSessionHas('error', 'Nothing changed: this redemption is already approved.');
        $this->post(route('bid-admin.approve', $held))
            ->assertSessionHas('error', 'Nothing changed: this redemption is already approved.');

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
    public function test_only_bid_admins_and_the_platform_admin_can_approve_or_reject(Role $role): void
    {
        $held = $this->heldRedemption();
        $entries = CreditLedgerEntry::count();

        $this->signIn($role);
        $this->post(route('bid-admin.approve', $held))->assertForbidden();
        $this->post(route('bid-admin.reject', $held))->assertForbidden();

        $this->assertTrue($held->fresh()->isUnderReview());
        $this->assertNull($held->fresh()->reviewed_by);
        $this->assertSame($entries, CreditLedgerEntry::count());
    }

    public function test_judges_get_403_on_approve_and_reject_even_with_view_access(): void
    {
        $held = $this->heldRedemption();
        $judge = User::factory()->role(Role::Judge)->create();

        // The policy itself refuses judges, not only the route's role check.
        $this->assertFalse($judge->can('review', $held));
        $this->actingAs($judge)->post(route('bid-admin.approve', $held))->assertForbidden();
        $this->actingAs($judge)->post(route('bid-admin.reject', $held))->assertForbidden();
        $this->assertTrue($held->fresh()->isUnderReview());
    }

    public function test_the_platform_admin_can_decide_and_is_recorded(): void
    {
        $held = $this->heldRedemption();
        $admin = $this->signIn(Role::PlatformAdmin);

        $this->post(route('bid-admin.reject', $held))->assertSessionHas('status');

        $this->assertSame($admin->name, $held->fresh()->reviewed_by);
        $this->assertSame($admin->id, $held->fresh()->reviewed_by_user_id);
        $this->assertSame($admin->name, CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_REVIEW_REJECTED)->sole()->decided_by);
    }

    public function test_guests_cannot_approve_or_reject(): void
    {
        $held = $this->heldRedemption();
        auth()->logout();

        $this->post(route('bid-admin.approve', $held))->assertRedirect('/login');
        $this->assertTrue($held->fresh()->isUnderReview());
    }
}
