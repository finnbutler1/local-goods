<?php

namespace Tests\Feature;

use App\Exceptions\RedemptionAlreadyReviewed;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
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

    private const REVIEWER = 'Sam Taylor (demo)';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
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
        $this->post('/demo/employee/redeem', ['merchant_id' => $deli->id, 'amount' => '20']);

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
        $this->review()->approve($redemption, self::REVIEWER);

        $redemption->refresh();
        $this->assertSame(Redemption::STATUS_APPROVED, $redemption->status);
        $this->assertSame(self::REVIEWER, $redemption->reviewed_by);
        $this->assertTrue($redemption->reviewed_at->eq('2026-10-12 15:04:00'));

        $entry = CreditLedgerEntry::where('redemption_id', $redemption->id)->where('type', CreditLedgerEntry::TYPE_REVIEW_APPROVED)->sole();
        $this->assertSame(0, $entry->amount_cents);
        $this->assertSame(self::REVIEWER, $entry->decided_by);
        $this->assertSame('2026-10-12 15:04:00', $entry->created_at->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('Approved by Sam Taylor (demo)', $entry->description);

        $this->assertSame($before - 2000, $employee->balanceCents(), 'Approval spends the reserved credits; nothing comes back.');
    }

    public function test_rejecting_returns_the_reserved_credits_and_records_who_and_when(): void
    {
        $employee = Employee::firstOrFail();
        $before = $employee->balanceCents();
        $redemption = $this->heldRedemption();

        Carbon::setTestNow('2026-10-12 15:10:00');
        $this->review()->reject($redemption, self::REVIEWER);

        $redemption->refresh();
        $this->assertSame(Redemption::STATUS_REJECTED, $redemption->status);
        $this->assertSame(self::REVIEWER, $redemption->reviewed_by);
        $this->assertTrue($redemption->reviewed_at->eq('2026-10-12 15:10:00'));

        $entry = CreditLedgerEntry::where('redemption_id', $redemption->id)->where('type', CreditLedgerEntry::TYPE_REVIEW_REJECTED)->sole();
        $this->assertSame(2000, $entry->amount_cents);
        $this->assertSame(self::REVIEWER, $entry->decided_by);
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
        $this->review()->{$first}($redemption, self::REVIEWER);
        $status = $redemption->fresh()->status;
        $entries = CreditLedgerEntry::count();
        $balance = $employee->balanceCents();

        try {
            // A stale copy, as if a second tab still showed it under review.
            $this->review()->{$second}(Redemption::find($redemption->id)->setAttribute('status', Redemption::STATUS_UNDER_REVIEW), 'Someone else (demo)');
            $this->fail('A second decision should be refused.');
        } catch (RedemptionAlreadyReviewed) {
        }

        $this->assertSame($status, $redemption->fresh()->status);
        $this->assertSame(self::REVIEWER, $redemption->fresh()->reviewed_by);
        $this->assertSame($entries, CreditLedgerEntry::count());
        $this->assertSame($balance, $employee->balanceCents());
    }

    public function test_a_redemption_the_ai_cleared_cannot_be_reviewed(): void
    {
        $approved = Redemption::where('status', Redemption::STATUS_APPROVED)->firstOrFail();
        $entries = CreditLedgerEntry::count();

        $this->expectException(RedemptionAlreadyReviewed::class);

        try {
            $this->review()->reject($approved, self::REVIEWER);
        } finally {
            $this->assertSame($entries, CreditLedgerEntry::count());
            $this->assertSame(Redemption::STATUS_APPROVED, $approved->fresh()->status);
        }
    }

    public function test_held_redemptions_show_approve_and_reject_buttons_and_others_do_not(): void
    {
        $held = $this->heldRedemption();
        $cleared = Redemption::where('status', Redemption::STATUS_APPROVED)->firstOrFail();

        $this->get('/demo/bid-admin')
            ->assertOk()
            ->assertSee('Waiting for your decision.')
            ->assertSee('action="'.route('demo.bid-admin.approve', $held).'"', false)
            ->assertSee('action="'.route('demo.bid-admin.reject', $held).'"', false)
            ->assertSee('aria-label="Approve $20.00 at Press Room Deli"', false)
            ->assertDontSee('action="'.route('demo.bid-admin.approve', $cleared).'"', false)
            ->assertDontSee('action="'.route('demo.bid-admin.reject', $cleared).'"', false);
    }

    public function test_approve_button_approves_and_shows_who_decided(): void
    {
        $held = $this->heldRedemption();
        Carbon::setTestNow(Carbon::parse('2026-10-12 15:04', 'America/New_York'));

        $this->post(route('demo.bid-admin.approve', $held))
            ->assertRedirect(route('demo.bid-admin')."#redemption-{$held->id}")
            ->assertSessionHas('status', 'Approved $20.00 at Press Room Deli. The reserved credits are spent.');

        $this->assertSame(Redemption::STATUS_APPROVED, $held->fresh()->status);
        $this->assertSame('Sam Taylor (demo)', $held->fresh()->reviewed_by);

        $this->get('/demo/bid-admin')
            ->assertSee('Approved by')
            ->assertSee('Sam Taylor (demo)')
            ->assertSee('Oct 12, 3:04 PM')
            ->assertDontSee('Waiting for your decision.');
    }

    public function test_reject_button_rejects_and_the_employee_gets_the_credits_back(): void
    {
        $employee = Employee::firstOrFail();
        $before = $employee->balanceCents();
        $held = $this->heldRedemption();

        $this->post(route('demo.bid-admin.reject', $held))
            ->assertSessionHas('status', "Rejected \$20.00 at Press Room Deli. The credits are back in the employee's balance.");

        $this->assertSame(Redemption::STATUS_REJECTED, $held->fresh()->status);
        $this->assertSame($before, $employee->balanceCents());

        $this->get('/demo/bid-admin')->assertSee('Rejected by')->assertSee('The credits went back to the employee.');
        $this->get('/demo/employee')->assertSee('Rejected');
    }

    public function test_a_second_click_is_refused_and_changes_nothing(): void
    {
        $held = $this->heldRedemption();
        $this->post(route('demo.bid-admin.approve', $held));
        $entries = CreditLedgerEntry::count();

        $this->post(route('demo.bid-admin.reject', $held))
            ->assertRedirect(route('demo.bid-admin')."#redemption-{$held->id}")
            ->assertSessionHas('error', 'Nothing changed: this redemption is already approved.');
        $this->post(route('demo.bid-admin.approve', $held))
            ->assertSessionHas('error', 'Nothing changed: this redemption is already approved.');

        $this->assertSame(Redemption::STATUS_APPROVED, $held->fresh()->status);
        $this->assertSame($entries, CreditLedgerEntry::count());
    }
}
