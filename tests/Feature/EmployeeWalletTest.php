<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

class EmployeeWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Oct 20, 2026 at noon in DC: two allowances minus $180 seeded spending = $120.00.
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::Employee);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeClaude(FakeClaudeClient $fake): void
    {
        $this->app->instance(ClaudeClient::class, $fake);
    }

    private function merchant(string $name): Merchant
    {
        return Merchant::where('name', $name)->firstOrFail();
    }

    public function test_wallet_shows_balance_and_allowance(): void
    {
        $this->get('/employee')
            ->assertOk()
            ->assertSee('$120.00')
            ->assertSee('$150.00 added each month by Meridian Policy Group')
            ->assertSee('Press Room Deli');
    }

    public function test_a_normal_redemption_is_approved(): void
    {
        $this->fakeClaude(FakeClaudeClient::verdict(6, 'clear', 'A $6.50 coffee is typical.'));

        $this->followingRedirects()
            ->post('/employee/redeem', ['merchant_id' => $this->merchant('Farragut Grind')->id, 'amount' => '6.50'])
            ->assertOk()
            ->assertSee('Approved')
            ->assertSee('$113.50');

        $redemption = Redemption::latest('id')->firstOrFail();
        $this->assertSame(Redemption::STATUS_APPROVED, $redemption->status);
        $this->assertSame(650, $redemption->amount_cents);
        $this->assertSame(11350, Employee::firstOrFail()->balanceCents());
    }

    public function test_the_ai_call_happens_outside_the_database_transaction(): void
    {
        $fake = FakeClaudeClient::verdict(6, 'clear');
        $this->fakeClaude($fake);
        // RefreshDatabase wraps each test in its own transaction; that is the baseline.
        $baseline = DB::transactionLevel();

        $this->post('/employee/redeem', ['merchant_id' => $this->merchant('Farragut Grind')->id, 'amount' => '6.50']);

        $this->assertCount(1, $fake->calls);
        $this->assertSame($baseline, $fake->calls[0]['transaction_level']);
    }

    public function test_suspicious_button_prefills_full_balance_round_number(): void
    {
        $this->get('/employee?suspicious=1')
            ->assertOk()
            ->assertSee('value="120"', false)
            ->assertSee('Try a suspicious redemption');
    }

    public function test_the_suspicious_redemption_is_held(): void
    {
        $this->fakeClaude(FakeClaudeClient::verdict(92, 'hold', 'The whole $120 balance as a round number.'));

        $this->followingRedirects()
            ->post('/employee/redeem', ['merchant_id' => $this->merchant('Press Room Deli')->id, 'amount' => '120'])
            ->assertOk()
            ->assertSee('Under review')
            ->assertSee('$0.00');

        $redemption = Redemption::latest('id')->firstOrFail();
        $this->assertSame(Redemption::STATUS_UNDER_REVIEW, $redemption->status);
        $this->assertTrue($redemption->signals['is_full_balance']);
        $this->assertTrue($redemption->signals['is_round_number']);
    }

    public function test_an_api_failure_holds_the_redemption(): void
    {
        $this->fakeClaude(new FakeClaudeClient(new RuntimeException('503 Service Unavailable')));

        $this->followingRedirects()
            ->post('/employee/redeem', ['merchant_id' => $this->merchant('Farragut Grind')->id, 'amount' => '6.50'])
            ->assertSee('Under review');

        $redemption = Redemption::latest('id')->firstOrFail();
        $this->assertSame(Redemption::STATUS_UNDER_REVIEW, $redemption->status);
        $this->assertSame('fallback', $redemption->scored_by);
    }

    public function test_cannot_redeem_more_than_the_balance(): void
    {
        $fake = FakeClaudeClient::verdict(5, 'clear');
        $this->fakeClaude($fake);
        $before = Redemption::count();

        $this->post('/employee/redeem', ['merchant_id' => $this->merchant('Farragut Grind')->id, 'amount' => '500'])
            ->assertSessionHasErrors('amount');

        $this->assertSame($before, Redemption::count());
        $this->assertSame([], $fake->calls);
    }

    public function test_rejects_zero_and_malformed_amounts(): void
    {
        $id = $this->merchant('Farragut Grind')->id;

        $this->post('/employee/redeem', ['merchant_id' => $id, 'amount' => '0'])->assertSessionHasErrors('amount');
        $this->post('/employee/redeem', ['merchant_id' => $id, 'amount' => 'ten'])->assertSessionHasErrors('amount');
    }

    public function test_the_confirmation_screen_shows_amount_merchant_and_number(): void
    {
        $this->fakeClaude(FakeClaudeClient::verdict(6, 'clear'));

        $this->followingRedirects()
            ->post('/employee/redeem', ['merchant_id' => $this->merchant('Farragut Grind')->id, 'amount' => '6.50'])
            ->assertSeeInOrder(['Approved', '$6.50', 'Farragut Grind', 'LG-'.str_pad((string) Redemption::max('id'), 6, '0', STR_PAD_LEFT), 'Show this screen at the counter']);

        // Shown once: going back to the wallet doesn't repeat it.
        $this->get('/employee')->assertDontSee('Show this screen at the counter');
    }

    public function test_browse_filters_by_category_and_search(): void
    {
        $this->get('/employee?category=salon')->assertOk()
            ->assertSee('K Street Cuts')
            ->assertSee('aria-current="true"', false)
            ->assertDontSee('Redeem here<span class="sr-only"> at Farragut Grind', false);

        $this->get('/employee?q=noodle')
            ->assertSee('Redeem here<span class="sr-only"> at Connecticut Noodle Bar', false)
            ->assertDontSee('Redeem here<span class="sr-only"> at Page &amp; Quill Books', false);

        $this->get('/employee?q=pizza')->assertSee('No merchants match &quot;pizza&quot;', false)->assertSee('Show all merchants');
    }

    public function test_redeem_here_picks_that_merchant(): void
    {
        $deli = $this->merchant('Press Room Deli');

        $this->get('/employee?merchant='.$deli->id)
            ->assertSee('<option value="'.$deli->id.'" selected', false);
    }

    public function test_history_lists_ledger_entries_with_the_balance_after_each(): void
    {
        $employee = Employee::orderBy('id')->firstOrFail();
        $entries = $employee->ledgerEntries()->latest('created_at')->latest('id')->take(2)->get();
        $balance = $employee->balanceCents();

        $this->get('/employee')->assertOk()
            ->assertSee('Transaction history')
            ->assertSeeInOrder([
                $entries[0]->description, 'Balance '.\App\Support\Money::format($balance),
                $entries[1]->description, 'Balance '.\App\Support\Money::format($balance - $entries[0]->amount_cents),
            ]);

        // Jordan has 12 redemptions plus allowances, more than the first 20 shown.
        $count = $employee->ledgerEntries()->count();
        if ($count > 20) {
            $this->get('/employee')->assertSee("Show all {$count} transactions");
            $this->get('/employee?history=1')->assertDontSee('Show all');
        }
    }

    public function test_the_wallet_card_says_demo_account_without_the_name(): void
    {
        $this->get('/employee')->assertSee('Demo account')->assertDontSee('Jordan Rivera (demo)')->assertDontSee('Demo employee:');
    }

    public function test_an_approved_screen_is_live_and_says_the_merchant_sees_it(): void
    {
        $this->fakeClaude(FakeClaudeClient::verdict(6, 'clear'));

        $this->followingRedirects()
            ->post('/employee/redeem', ['merchant_id' => $this->merchant('Farragut Grind')->id, 'amount' => '6.50'])
            ->assertSee('data-live-clock', false)
            ->assertSee('role="timer"', false)
            ->assertSee('motion-safe:animate-ping', false)
            ->assertSee('Farragut Grind also sees this redemption on their Local Goods dashboard.');
    }

    public function test_an_under_review_screen_has_no_live_clock(): void
    {
        $this->fakeClaude(FakeClaudeClient::verdict(92, 'hold'));

        $this->followingRedirects()
            ->post('/employee/redeem', ['merchant_id' => $this->merchant('Press Room Deli')->id, 'amount' => '120'])
            ->assertSee('Under review')
            ->assertDontSee('data-live-clock', false);
    }
}
