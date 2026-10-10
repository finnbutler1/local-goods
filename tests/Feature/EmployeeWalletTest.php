<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
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
        $this->get('/demo/employee')
            ->assertOk()
            ->assertSee('$120.00')
            ->assertSee('$150.00 added each month by Meridian Policy Group')
            ->assertSee('Press Room Deli');
    }

    public function test_a_normal_redemption_is_approved(): void
    {
        $this->fakeClaude(FakeClaudeClient::verdict(6, 'clear', 'A $6.50 coffee is typical.'));

        $this->followingRedirects()
            ->post('/demo/employee/redeem', ['merchant_id' => $this->merchant('Farragut Grind')->id, 'amount' => '6.50'])
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

        $this->post('/demo/employee/redeem', ['merchant_id' => $this->merchant('Farragut Grind')->id, 'amount' => '6.50']);

        $this->assertCount(1, $fake->calls);
        $this->assertSame($baseline, $fake->calls[0]['transaction_level']);
    }

    public function test_suspicious_button_prefills_full_balance_round_number(): void
    {
        $this->get('/demo/employee?suspicious=1')
            ->assertOk()
            ->assertSee('value="120"', false)
            ->assertSee('Try a suspicious redemption');
    }

    public function test_the_suspicious_redemption_is_held(): void
    {
        $this->fakeClaude(FakeClaudeClient::verdict(92, 'hold', 'The whole $120 balance as a round number.'));

        $this->followingRedirects()
            ->post('/demo/employee/redeem', ['merchant_id' => $this->merchant('Press Room Deli')->id, 'amount' => '120'])
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
            ->post('/demo/employee/redeem', ['merchant_id' => $this->merchant('Farragut Grind')->id, 'amount' => '6.50'])
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

        $this->post('/demo/employee/redeem', ['merchant_id' => $this->merchant('Farragut Grind')->id, 'amount' => '500'])
            ->assertSessionHasErrors('amount');

        $this->assertSame($before, Redemption::count());
        $this->assertSame([], $fake->calls);
    }

    public function test_rejects_zero_and_malformed_amounts(): void
    {
        $id = $this->merchant('Farragut Grind')->id;

        $this->post('/demo/employee/redeem', ['merchant_id' => $id, 'amount' => '0'])->assertSessionHasErrors('amount');
        $this->post('/demo/employee/redeem', ['merchant_id' => $id, 'amount' => 'ten'])->assertSessionHasErrors('amount');
    }
}
