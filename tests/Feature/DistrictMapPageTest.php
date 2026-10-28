<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Services\Districts\DistrictCheck;
use App\Services\Districts\DistrictRules;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesDcAddresses;
use Tests\TestCase;

/**
 * The public district map (ADR 0031): an inline SVG from the stored
 * boundary with the live demo merchants, a text alternative, and an
 * address checker that runs the enforcement code and stores no typed text.
 */
class DistrictMapPageTest extends TestCase
{
    use FakesDcAddresses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeDcAddresses();
    }

    public function test_the_map_draws_the_boundary_and_pins_with_a_text_alternative_and_no_outside_requests(): void
    {
        $html = $this->get('/district')->assertOk()
            ->assertSee('Golden Triangle BID')
            ->assertSee('role="group" aria-labelledby="map-title"', false)
            ->assertSee('aria-labelledby="map-title"', false)
            ->assertSee('Merchants on the map')
            ->assertSee('Demo data: these businesses are fictional')
            ->assertSee('Business Improvement Districts')
            ->assertSee('February 17, 2026')
            ->getContent();

        $this->assertSame(Merchant::count(), substr_count($html, 'data-map-pin="merchant"'));
        foreach (Merchant::all() as $merchant) {
            $this->assertStringContainsString('id="merchant-'.$merchant->id.'"', $html);
        }
        $this->assertDoesNotMatchRegularExpression('/<(img|iframe|script)[^>]+src="https?:\/\/(?!localhost)/', $html, 'no map tiles or outside scripts');
        Http::assertNothingSent();
    }

    public function test_a_merchant_that_is_not_live_is_not_on_the_map(): void
    {
        Merchant::where('name', 'Triangle Stems')->update(['live_at' => null]);

        $this->get('/district')->assertOk()->assertDontSee('Triangle Stems');
    }

    public function test_checking_an_address_inside_and_outside(): void
    {
        $this->get('/district?address='.urlencode('1700 K St NW'))->assertOk()
            ->assertSee('data-check-result="inside"', false)
            ->assertSee('Inside the Golden Triangle BID.')
            ->assertSee('same check Local Goods runs before a BID can approve a merchant')
            ->assertSee('data-map-pin="checked"', false);

        $this->get('/district?address='.urlencode('50 Massachusetts Ave NE'))->assertOk()
            ->assertSee('data-check-result="outside"', false)
            ->assertSee('Outside the Golden Triangle BID.')
            ->assertDontSee('data-map-pin="checked"', false);  // off the drawing
    }

    public function test_the_checker_uses_the_enforcement_code(): void
    {
        $spy = new class(app(\App\Services\Geo\AddressLookup::class)) extends DistrictCheck
        {
            public int $calls = 0;

            public function address(\App\Models\District $district, string $address, string $origin): \App\Services\Districts\DistrictCheckResult
            {
                $this->calls++;

                return parent::address($district, $address, $origin);
            }
        };
        $this->app->instance(DistrictCheck::class, $spy);

        $this->get('/district?address='.urlencode('1700 K St NW'))->assertOk();

        $this->assertSame(1, $spy->calls);
        // And DistrictCheck decides with the approval rule itself.
        $source = (string) file_get_contents(app_path('Services/Districts/DistrictCheck.php'));
        $this->assertStringContainsString('DistrictRules::isInside(', $source);
        $this->assertStringContainsString('self::isInside($district', (string) file_get_contents(app_path('Services/Districts/DistrictRules.php')));
        $this->assertTrue(DistrictRules::isInside(\App\Models\District::default(), self::DC_ADDRESSES['1700 K STREET NW'][0], self::DC_ADDRESSES['1700 K STREET NW'][1]));
    }

    public function test_the_typed_address_is_not_stored(): void
    {
        $this->get('/district?address='.urlencode('1700 K St NW, my office on floor 9'))->assertOk();

        $row = (array) DB::table('address_lookups')->sole();
        $this->assertSame('checker', $row['origin']);
        $this->assertNull($row['query']);
        $this->assertStringNotContainsString('floor 9', json_encode($row));
    }

    public function test_address_checks_are_rate_limited_but_the_map_is_not(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->get('/district?address='.urlencode("{$i} K St NW"))->assertOk();
        }
        $this->get('/district?address='.urlencode('1700 K St NW'))->assertStatus(429);
        $this->get('/district')->assertOk();
    }
}
