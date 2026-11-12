<?php

namespace Tests\Feature;

use App\Enums\DenialReason;
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
use App\Support\ShowMore;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PlacesMerchantsInTheDistrict;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The merchant's view: owed = cleared redemptions minus payouts, never a
 * held one; held redemptions are "waiting for review, not owed yet";
 * payouts are marked simulated until Stripe test mode.
 */
class MerchantDashboardTest extends TestCase
{
    use PlacesMerchantsInTheDistrict;
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
        app(RedemptionReviewService::class)->deny($rejected, User::factory()->role(Role::BidAdmin)->create(), 'No purchase happened.', DenialReason::NoPurchase);

        $this->get('/merchant')->assertOk()
            ->assertSee('Owed to you now')
            ->assertSee('Cleared sales this month')
            ->assertDontSee('Local Goods sales this month')
            ->assertSee(Money::format($owedBefore + 450))
            ->assertSee('Waiting for review, not owed yet')
            ->assertSee('$12.00')
            ->assertSee('Denied, not owed')
            ->assertSee('AI held (risk 90) → denied by BID admin')
            ->assertSee('J.R.');

        $this->assertSame($owedBefore + 450, $this->merchant->payableCents());

        app(RedemptionReviewService::class)->approve($held, User::factory()->role(Role::BidAdmin)->create(), 'Checked with the merchant.');
        $this->assertSame($owedBefore + 450 + 1200, $this->merchant->payableCents());
    }

    public function test_payouts_are_listed_as_simulated_and_reduce_what_is_owed(): void
    {
        $this->redeem(1000, 'clear');
        $owed = $this->merchant->payableCents();
        app(CreditLedger::class)->payout($this->merchant, $owed, MerchantPayout::STATUS_SIMULATED);

        $this->assertSame(0, $this->merchant->payableCents());
        $this->get('/merchant')->assertSee('Payout history')->assertSee('Simulated')->assertSee('no money moved')->assertSee(Money::format($owed));
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

    public function test_redemptions_received_loads_the_newest_fifty_and_keeps_the_reload_link(): void
    {
        $total = $this->merchant->redemptions()->count();
        $this->assertGreaterThan(5, $total, 'The seed gives Farragut Grind more than five');
        $this->assertLessThanOrEqual(ShowMore::CAP, $total);

        // Up to fifty fit in the page: the button reveals them, no reload link.
        $html = $this->get('/merchant')->assertSee("The newest {$total} of {$total}")->assertDontSee("Show all {$total} redemptions")->getContent();
        $this->assertSame($total, $this->receivedRows($html));
        $this->assertSame($total - ShowMore::RECEIVED, preg_match_all('/data-received-row\\s+data-more/', $html));

        // Past fifty the page carries fifty and the old link loads the lot.
        $copy = $this->merchant->redemptions()->orderBy('id')->firstOrFail();
        for ($i = $total; $i < ShowMore::CAP + 6; $i++) {
            $copy->replicate()->save();
        }
        $all = $this->merchant->redemptions()->count();

        $html = $this->get('/merchant')->assertSee('The newest '.ShowMore::CAP." of {$all}")->assertSee("Show all {$all} redemptions")->getContent();
        $this->assertSame(ShowMore::CAP, $this->receivedRows($html));

        $html = $this->get('/merchant?all=1')->assertSee("All {$all}")->assertDontSee("Show all {$all} redemptions")->assertSee('Show the newest '.ShowMore::CAP.' only')->getContent();
        $this->assertSame($all, $this->receivedRows($html));
    }

    public function test_no_show_all_button_with_five_or_fewer(): void
    {
        $owner = User::factory()->role(Role::Merchant)->create();
        $this->merchant = Merchant::create(['bid_id' => $this->merchant->bid_id, 'name' => 'Test Bakery', 'category' => 'cafe', 'address' => '1 Test St NW', 'user_id' => $owner->id, ...$this->inTheDistrict()]);
        $this->actingAs($owner);
        foreach ([300, 400, 500] as $cents) {
            $this->redeem($cents, 'clear');
        }

        $html = $this->get('/merchant')->assertOk()->assertSee('The newest 3 of 3')->assertDontSee('Show all')->getContent();
        $this->assertSame(3, $this->receivedRows($html));
    }

    private function receivedRows(string $html): int
    {
        $start = strpos($html, 'id="received-heading"');

        return substr_count(substr($html, $start, strpos($html, 'id="payouts-heading"') - $start), 'data-received-row');
    }
}
