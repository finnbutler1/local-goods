<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BidProgramFeePayment;
use App\Models\District;
use App\Support\BidProgramFee;
use App\Support\ProductionConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\Concerns\PaysBidProgramFee;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * The BID annual program fee's setting, price and program years (ADR 0044):
 * the price comes only from the district's tier, every district (Golden
 * Triangle included) pays from its start date, and with the switch off
 * there is no button and no charge.
 */
class BidProgramFeeTest extends TestCase
{
    use PaysBidProgramFee;
    use RefreshDatabase;
    use UsesFakeStripe;

    private const ENV = 'BID_PROGRAM_FEE_ENABLED';

    public function test_it_is_off_unless_the_setting_is_exactly_true(): void
    {
        $this->assertFalse(BidProgramFee::enabled());
        $this->assertFalse(BidProgramFee::charges());
        $this->assertSame('planned, not charged in the app', BidProgramFee::status());
    }

    public function test_no_deploy_file_or_seeder_switches_it_on(): void
    {
        $this->assertStringContainsString("env('".self::ENV."', false) === true", File::get(config_path('payments.php')));

        $files = collect([...File::allFiles(base_path('.ebextensions')), ...File::allFiles(base_path('.platform')), ...File::allFiles(base_path('scripts')),
            ...File::allFiles(database_path('seeders'))])
            ->merge(File::glob(base_path('.github/workflows/*')))->merge([base_path('.env.example'), base_path('phpunit.xml')]);
        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('/'.self::ENV.'\s*[:=]\s*["\']?\w/', File::get((string) $file), (string) $file);
            $this->assertStringNotContainsString('bid_program_fee_enabled', File::get((string) $file), (string) $file);
        }
    }

    public function test_the_price_comes_from_the_tier_of_the_member_count_only(): void
    {
        foreach ([1 => ['Small', 100000], 999 => ['Small', 100000], 1000 => ['Medium', 250000], 3999 => ['Medium', 250000],
            4000 => ['Large', 500000], 6000 => ['Large', 500000]] as $members => [$name, $cents]) {
            $tier = BidProgramFee::tierFor($members);
            $this->assertSame([$name, $cents], [$tier['name'], $tier['fee_cents']], "{$members} member businesses");
        }

        // The projection's pilot district is the Large tier, the same figure.
        $this->assertSame(config('projections.assumptions.bid_program_fee_pilot.base') * 100, BidProgramFee::tierFor(6000)['fee_cents']);
        $this->assertSame(config('projections.assumptions.bid_program_fee_other.base') * 100, BidProgramFee::tierFor(1000)['fee_cents']);
    }

    public function test_every_district_including_golden_triangle_pays_from_its_start_date(): void
    {
        $district = $this->setUpDistrict(6000, '2026-10-01');
        $this->assertSame('golden-triangle', $district->slug);

        $at = fn (string $when) => BidProgramFee::programYear($district, Carbon::parse($when, 'America/New_York'));

        $this->assertNull($at('2026-09-30 23:59'), 'Nothing is due before the start date.');
        $first = $at('2026-10-01 00:01');
        $this->assertSame(1, $first['year'], 'Year 1 is due from the start date: no free pilot year.');
        $this->assertSame(['2026-10-01', '2027-09-30'], [$first['starts_on']->format('Y-m-d'), $first['ends_on']->format('Y-m-d')]);
        $this->assertSame(1, $at('2027-09-30 23:59')['year']);
        $second = $at('2027-10-01 00:01');
        $this->assertSame([2, '2027-10-01', '2028-09-30'], [$second['year'], $second['starts_on']->format('Y-m-d'), $second['ends_on']->format('Y-m-d')]);

        $district->forceFill(['program_starts_on' => null])->save();
        $this->assertNull(BidProgramFee::programYear($district->refresh()), 'Not set up: nothing is due.');
    }

    public function test_unusable_tiers_are_refused_and_stop_a_deploy(): void
    {
        $this->switchOnBidFee();
        $this->assertNull(BidProgramFee::problem());

        $tiers = config('projections.bid_fee_tiers');
        foreach ([
            [],
            [['name' => 'Small', 'min_businesses' => 10, 'fee' => 1000]],
            [['name' => 'Small', 'min_businesses' => 0, 'fee' => 1000], ['name' => 'Medium', 'min_businesses' => 0, 'fee' => 2500]],
            [['name' => 'Small', 'min_businesses' => 0, 'fee' => 0]],
            [['name' => 'Small', 'min_businesses' => 0, 'fee' => 1_000_000]],
            [['name' => 'Small', 'fee' => 1000]],
        ] as $bad) {
            config(['projections.bid_fee_tiers' => $bad]);
            $this->assertNotNull(BidProgramFee::problem(), json_encode($bad));
            $this->assertFalse(BidProgramFee::charges());
            $this->assertContains(BidProgramFee::problem(), ProductionConfig::problems());
        }
        config(['projections.bid_fee_tiers' => $tiers]);
    }

    public function test_status_follows_the_setting_and_stripe_mode(): void
    {
        $this->switchOnBidFee();
        $this->assertStringContainsString('nothing is charged', BidProgramFee::status(), 'On without Stripe test keys: still nothing charged.');
        $this->assertFalse(BidProgramFee::charges());

        $this->fakeStripe();
        $this->assertTrue(BidProgramFee::charges());
        $this->assertSame('charged in Stripe test mode: no real money', BidProgramFee::status());
    }

    public function test_with_the_switch_off_there_is_no_button_and_no_charge(): void
    {
        $this->fakeStripe();
        $this->setUpDistrict();
        $this->travelTo(Carbon::parse('2026-11-13 12:00', 'America/New_York'));
        $this->actingAs($this->signIn(Role::BidAdmin));

        $this->get(route('bid-admin.program-fee'))->assertOk()
            ->assertSee('The BID program fee is planned, not charged in the app.')
            ->assertDontSee('data-pay-program-fee', false)
            ->assertDontSee('Pay this year');

        $this->post(route('bid-admin.program-fee.pay'), ['amount_cents' => 1])->assertNotFound();
        $this->assertSame(0, BidProgramFeePayment::count());
        $this->assertSame([], $this->stripe->callsOf('checkout'));
    }

    public function test_switched_on_without_stripe_keys_there_is_still_no_button(): void
    {
        $this->switchOnBidFee();
        $this->setUpDistrict();
        $this->travelTo(Carbon::parse('2026-11-13 12:00', 'America/New_York'));
        $this->actingAs($this->signIn(Role::BidAdmin));

        $this->get(route('bid-admin.program-fee'))->assertOk()->assertDontSee('data-pay-program-fee', false);
        $this->post(route('bid-admin.program-fee.pay'))->assertNotFound();
        $this->assertSame(0, BidProgramFeePayment::count());
    }

    public function test_the_page_says_not_set_until_the_district_is_set_up(): void
    {
        $this->fakeStripe();
        $this->switchOnBidFee();
        $this->assertFalse(BidProgramFee::isSetUp(District::default()));
        $this->actingAs($this->signIn(Role::BidAdmin));

        $this->get(route('bid-admin.program-fee'))->assertOk()->assertSee('data-not-set', false)->assertDontSee('data-pay-program-fee', false);
        $this->post(route('bid-admin.program-fee.pay'))->assertRedirect(route('bid-admin.program-fee'))->assertSessionHas('error');
        $this->assertSame(0, BidProgramFeePayment::count());
    }
}
