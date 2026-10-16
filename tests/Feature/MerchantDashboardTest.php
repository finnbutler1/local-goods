<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
use App\Services\RedemptionReviewService;
use App\Services\RedemptionService;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The merchant's view: owed = cleared redemptions minus payouts, never a
 * held one; held redemptions are "waiting for review, not owed yet";
 * payouts are marked simulated until Stripe test mode.
 */
class MerchantDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::Merchant);
        $this->merchant = Merchant::where('name', 'Farragut Grind')->sole();
    }

    private function redeem(int $cents, string $decision)
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict($decision === 'clear' ? 5 : 90, $decision));

        return app(RedemptionService::class)->redeem(Employee::orderBy('id')->firstOrFail(), $this->merchant, $cents);
    }

    public function test_owed_counts_cleared_only_and_held_is_shown_apart(): void
    {
        $owedBefore = $this->merchant->payableCents();
        $this->redeem(450, 'clear');
        $held = $this->redeem(1200, 'hold');
        $rejected = $this->redeem(800, 'hold');
        app(RedemptionReviewService::class)->reject($rejected, User::factory()->role(Role::BidAdmin)->create());

        $this->get('/merchant')->assertOk()
            ->assertSee('Owed to you now')
            ->assertSee('Cleared sales this month')
            ->assertDontSee('Local Goods sales this month')
            ->assertSee(Money::format($owedBefore + 450))
            ->assertSee('Waiting for review, not owed yet')
            ->assertSee('$12.00')
            ->assertSee('Rejected, not owed')
            ->assertSee('J.R.');

        $this->assertSame($owedBefore + 450, $this->merchant->payableCents());

        app(RedemptionReviewService::class)->approve($held, User::factory()->role(Role::BidAdmin)->create());
        $this->assertSame($owedBefore + 450 + 1200, $this->merchant->payableCents());
    }

    public function test_payouts_are_listed_as_simulated_and_reduce_what_is_owed(): void
    {
        $this->redeem(1000, 'clear');
        $owed = $this->merchant->payableCents();
        app(CreditLedger::class)->payout($this->merchant, $owed, MerchantPayout::STATUS_SIMULATED);

        $this->assertSame(0, $this->merchant->payableCents());
        $this->get('/merchant')->assertSee('Payout history')->assertSee('Simulated until Stripe test mode')->assertSee(Money::format($owed));
    }

    public function test_only_the_linked_business_is_shown(): void
    {
        $this->get('/merchant')->assertSee('Merchant dashboard · Farragut Grind')->assertDontSee('Merchant dashboard · K Street Cuts');
    }

    public function test_a_merchant_login_with_no_business_gets_an_empty_state(): void
    {
        $this->actingAs(User::factory()->role(Role::Merchant)->create());

        $this->get('/merchant')->assertOk()->assertSee('No business is linked to this login yet');
    }

    public function test_a_judge_sees_the_demo_merchant_read_only(): void
    {
        $this->signIn(Role::Judge);

        $this->get('/merchant')->assertOk()->assertSee('Farragut Grind')->assertDontSee('<form method="POST" action="'.url('/merchant'), false);
    }
}
