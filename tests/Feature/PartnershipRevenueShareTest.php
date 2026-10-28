<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Bid;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\Partnership;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\Districts\PartnershipRevenueShare;
use App\Services\Districts\PartnershipSettings;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SetsMerchantFee;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The BID's revenue share (ADR 0031) is worked out from the platform's own
 * ledger lines for the district, half up, and is display only: working it
 * out writes nothing.
 */
class PartnershipRevenueShareTest extends TestCase
{
    use RefreshDatabase;
    use SetsMerchantFee;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(5, 'clear'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function share(int $bps): Partnership
    {
        $partnership = Partnership::sole();
        app(PartnershipSettings::class)->change(User::factory()->role(Role::PlatformAdmin)->create(), $partnership,
            [...$partnership->terms(), 'revenue_share_bps' => $bps], 'Test share', $partnership->latestChangeId());

        return $partnership->fresh();
    }

    public function test_with_no_fees_the_revenue_and_share_are_zero_at_any_rate(): void
    {
        $partnership = $this->share(2500);

        $this->assertSame(['revenue_cents' => 0, 'share_cents' => 0], app(PartnershipRevenueShare::class)->thisMonth($partnership));
        $this->assertSame(['revenue_cents' => 0, 'share_cents' => 0], app(PartnershipRevenueShare::class)->sinceStart($partnership));
    }

    public function test_the_share_is_the_rate_of_merchant_fees_kept_in_the_district_half_up(): void
    {
        $this->setMerchantFee(300);
        $grind = Merchant::where('name', 'Farragut Grind')->sole();
        $redemption = app(RedemptionService::class)->redeem(Employee::orderBy('id')->firstOrFail(), $grind, 1250);
        $this->assertSame(38, (int) $redemption->merchant_fee_cents);   // 3% of $12.50 = 37.5, half up

        $partnership = $this->share(1250);  // 12.5%
        $entries = CreditLedgerEntry::count();

        $month = app(PartnershipRevenueShare::class)->thisMonth($partnership);

        $this->assertSame(38, $month['revenue_cents']);
        $this->assertSame(5, $month['share_cents']);   // 12.5% of 38 = 4.75, half up
        $this->assertSame($entries, CreditLedgerEntry::count(), 'working out the share writes nothing');
    }

    public function test_platform_fees_from_employers_outside_the_district_program_are_not_counted(): void
    {
        $outsider = Employer::create(['bid_id' => Bid::value('id'), 'name' => 'No Program Co', 'monthly_allowance_cents' => 1000]);
        $purchase = $outsider->creditPurchases()->create(['amount_cents' => 10000, 'platform_fee_cents' => 300, 'reference' => 'X-1', 'purchased_at' => now(), 'status' => 'simulated']);
        DB::table('credit_ledger_entries')->insert(['platform' => true, 'amount_cents' => 300, 'type' => CreditLedgerEntry::TYPE_PLATFORM_FEE,
            'credit_purchase_id' => $purchase->id, 'description' => 'Test fee', 'created_at' => now()]);

        $this->assertSame(0, app(PartnershipRevenueShare::class)->thisMonth($this->share(1000))['revenue_cents']);

        Employer::whereKey($outsider->id)->update(['district_id' => Partnership::sole()->district_id]);
        $this->assertSame(['revenue_cents' => 300, 'share_cents' => 30], app(PartnershipRevenueShare::class)->thisMonth(Partnership::sole()));
    }

    public function test_share_of_rounds_half_up_and_never_goes_negative(): void
    {
        $this->assertSame(0, PartnershipRevenueShare::shareOf(0, 5000));
        $this->assertSame(0, PartnershipRevenueShare::shareOf(-500, 5000));
        $this->assertSame(1, PartnershipRevenueShare::shareOf(1, 5000));
        $this->assertSame(0, PartnershipRevenueShare::shareOf(1, 4999));
        $this->assertSame(0, PartnershipRevenueShare::shareOf(100000, 0));
    }
}
