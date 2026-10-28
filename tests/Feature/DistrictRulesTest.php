<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Bid;
use App\Models\District;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\PayCode;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\Districts\DistrictRules;
use App\Services\PayCodes;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Concerns\PlacesMerchantsInTheDistrict;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The district rules (ADR 0031): a merchant is approved only inside the
 * boundary, and district program credits are spent only at approved, live
 * merchants in that district. Each test fails if its rule is broken.
 */
class DistrictRulesTest extends TestCase
{
    use PlacesMerchantsInTheDistrict;
    use RefreshDatabase;

    private const INSIDE = [38.9020, -77.0390];   // Farragut Square

    private const OUTSIDE = [38.8973, -77.0063];  // Union Station

    private FakeClaudeClient $claude;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $this->claude = FakeClaudeClient::verdict(5, 'clear');
        $this->app->instance(ClaudeClient::class, $this->claude);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function jordan(): Employee
    {
        return Employee::orderBy('id')->firstOrFail();
    }

    private function newMerchant(array $columns = []): Merchant
    {
        return Merchant::create(['bid_id' => Bid::value('id'), 'name' => 'Test Shop', 'category' => 'cafe', 'address' => '1 Test St NW', ...$columns]);
    }

    private function admin(): User
    {
        return User::factory()->role(Role::BidAdmin)->create();
    }

    // Rule 1: approval only inside the boundary.

    public function test_a_merchant_inside_the_boundary_can_be_approved(): void
    {
        $merchant = $this->newMerchant(['latitude' => self::INSIDE[0], 'longitude' => self::INSIDE[1]]);

        DistrictRules::approveMerchant($merchant, District::default(), $admin = $this->admin());

        $merchant->refresh();
        $this->assertSame(District::default()->id, $merchant->district_id);
        $this->assertNotNull($merchant->district_approved_at);
        $this->assertSame($admin->id, $merchant->district_approved_by_user_id);
        $this->assertNull($merchant->live_at, 'approval alone does not make it live');
    }

    public function test_a_merchant_outside_the_boundary_is_refused(): void
    {
        $merchant = $this->newMerchant(['latitude' => self::OUTSIDE[0], 'longitude' => self::OUTSIDE[1]]);

        try {
            DistrictRules::approveMerchant($merchant, District::default(), $this->admin());
            $this->fail('A merchant outside the boundary was approved.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('outside the Golden Triangle BID boundary', $e->getMessage());
        }

        $this->assertNull($merchant->fresh()->district_approved_at);
    }

    public function test_a_merchant_with_no_location_is_refused(): void
    {
        $this->expectExceptionMessage(DistrictRules::NO_LOCATION);

        DistrictRules::approveMerchant($this->newMerchant(), District::default(), $this->admin());
    }

    public function test_no_write_can_approve_or_move_an_approved_merchant_outside(): void
    {
        try {
            $this->newMerchant(['district_id' => District::default()->id, 'district_approved_at' => now(), 'latitude' => self::OUTSIDE[0], 'longitude' => self::OUTSIDE[1]]);
            $this->fail('An approved merchant was created outside the boundary.');
        } catch (InvalidArgumentException) {
        }

        $grind = Merchant::where('name', 'Farragut Grind')->sole();
        try {
            $grind->update(['latitude' => self::OUTSIDE[0], 'longitude' => self::OUTSIDE[1]]);
            $this->fail('An approved merchant was moved outside the boundary.');
        } catch (InvalidArgumentException) {
        }

        $this->assertTrue(DistrictRules::isInside(District::default(), $grind->fresh()->latitude, $grind->fresh()->longitude));
    }

    // Rule 2: district program credits only at approved, live merchants in that district.

    /** @return array<string, array{array<string, mixed>}> */
    public static function notRedeemable(): array
    {
        return [
            'no district' => [['district_id' => null, 'district_approved_at' => null]],
            'not approved' => [['district_approved_at' => null]],
            'approved but not live' => [['live_at' => null]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notRedeemable')]
    public function test_credits_are_refused_at_a_shop_that_is_not_approved_and_live_here(array $columns): void
    {
        $shop = $this->newMerchant([...$this->inTheDistrict(), ...$columns]);
        $before = [DB::table('redemptions')->count(), DB::table('credit_ledger_entries')->count(), $this->jordan()->balanceCents()];

        try {
            app(RedemptionService::class)->redeem($this->jordan(), $shop, 500);
            $this->fail('Credits were spent at a shop outside the program.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'That shop isn\'t an approved Local Goods merchant in the Golden Triangle BID, so your credits can\'t be spent there. Nothing was charged.',
                $e->errors()['merchant_id'][0],
            );
        }

        $this->assertSame($before, [DB::table('redemptions')->count(), DB::table('credit_ledger_entries')->count(), $this->jordan()->balanceCents()]);
        $this->assertCount(0, $this->claude->calls, 'a refused shop never reaches the AI');
    }

    public function test_a_merchant_in_another_district_is_refused(): void
    {
        $other = District::create([...District::default()->only(['boundary_geojson', 'boundary_sha256', 'description', 'source_name', 'source_url', 'source_edited_on', 'retrieved_on']), 'name' => 'Another BID', 'slug' => 'another']);
        $shop = $this->newMerchant([...$this->inTheDistrict(), 'district_id' => $other->id]);

        $this->expectException(ValidationException::class);
        app(RedemptionService::class)->redeem($this->jordan(), $shop, 500);
    }

    public function test_an_approved_live_merchant_in_the_district_is_paid_as_before(): void
    {
        $shop = $this->newMerchant($this->inTheDistrict());

        $redemption = app(RedemptionService::class)->redeem($this->jordan(), $shop, 500);

        $this->assertTrue($redemption->isApproved());
        $this->assertCount(1, $this->claude->calls);
    }

    public function test_an_employer_with_no_district_program_is_not_restricted(): void
    {
        $employer = Employer::create(['bid_id' => Bid::value('id'), 'name' => 'No Program Co', 'monthly_allowance_cents' => 1000]);
        $employee = Employee::create(['employer_id' => $employer->id, 'display_name' => 'Test Person']);

        $this->assertTrue(DistrictRules::mayRedeem($employee, $this->newMerchant()));
        $this->assertFalse(DistrictRules::mayRedeem($this->jordan(), $this->newMerchant()));
    }

    public function test_the_wallet_lists_only_redeemable_shops_and_refuses_a_posted_one(): void
    {
        $this->signIn(Role::Employee);
        $hidden = $this->newMerchant(['name' => 'Off The Map Cafe']);

        $this->get('/employee')->assertOk()->assertSee('Farragut Grind')->assertDontSee('Off The Map Cafe');

        $this->from('/employee')->post('/employee/redeem', ['merchant_id' => $hidden->id, 'amount' => '5.00'])
            ->assertRedirect('/employee')
            ->assertSessionHasErrors(['merchant_id' => str_replace(':district', 'Golden Triangle BID', DistrictRules::NOT_REDEEMABLE)]);
        $this->assertSame(0, $hidden->redemptions()->count());
    }

    public function test_no_pay_code_is_issued_for_a_shop_outside_the_program(): void
    {
        $this->expectException(ValidationException::class);
        $codes = PayCode::count();

        try {
            app(PayCodes::class)->issue($this->jordan(), $this->newMerchant(), 500);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pay_merchant_id', $e->errors());
            $this->assertSame($codes, PayCode::count());

            throw $e;
        }
    }

    public function test_a_pay_code_for_a_shop_no_longer_live_is_refused_without_being_called_a_balance_problem(): void
    {
        $grind = Merchant::where('name', 'Farragut Grind')->sole();
        $code = app(PayCodes::class)->issue($this->jordan(), $grind, 500);
        $grind->update(['live_at' => null]);

        try {
            app(PayCodes::class)->confirm($code, $grind);
            $this->fail('A pay code was confirmed at a shop that is not live.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('isn\'t an approved Local Goods merchant', $e->errors()['pay_code'][0]);
            $this->assertStringNotContainsString(PayCodes::NOT_ENOUGH_CREDITS, $e->errors()['pay_code'][0]);
        }

        $this->assertSame(PayCode::STATUS_ACTIVE, $code->fresh()->status, 'the code is left as it was, not cancelled as a balance failure');
        $this->assertNull($code->fresh()->cancelled_by);
        $this->assertCount(0, $this->claude->calls);
    }
}
