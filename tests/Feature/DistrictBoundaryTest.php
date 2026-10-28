<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Employer;
use App\Models\Merchant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\BidBoundaryTest;

/**
 * The stored district boundary (ADR 0031) is DC's published polygon,
 * unchanged, and every demo merchant sits inside it.
 */
class DistrictBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stored_boundary_is_the_committed_file_with_its_source(): void
    {
        $district = District::default();
        $file = (string) file_get_contents(base_path('data/sources/golden_triangle_bid.geojson'));

        $this->assertSame('Golden Triangle BID', $district->name);
        $this->assertSame(hash('sha256', $file), $district->boundary_sha256);
        $this->assertSame(hash('sha256', $file), hash('sha256', $district->boundary_geojson));
        $this->assertStringStartsWith('https://maps2.dcgis.dc.gov/', $district->source_url);
        $this->assertSame('2026-02-17', $district->source_edited_on->toDateString());
        $this->assertSame('2026-10-14', $district->retrieved_on->toDateString());
    }

    /** @return array<string, array{float, float, bool}> */
    public static function landmarks(): array
    {
        return BidBoundaryTest::landmarks();
    }

    #[DataProvider('landmarks')]
    public function test_the_stored_boundary_puts_landmarks_on_the_right_side(float $lat, float $lng, bool $inside): void
    {
        $this->assertSame($inside, District::default()->boundary()->contains($lat, $lng));
    }

    public function test_every_demo_merchant_point_is_inside(): void
    {
        $this->assertSame(array_keys(DatabaseSeeder::DEMO_MERCHANTS), array_keys(DatabaseSeeder::DEMO_COORDINATES));

        foreach (DatabaseSeeder::DEMO_COORDINATES as $key => [$lat, $lng]) {
            $this->assertTrue(District::default()->boundary()->contains($lat, $lng), "{$key} is outside the boundary");
        }
    }

    public function test_the_seed_places_every_merchant_and_the_employer_in_the_district(): void
    {
        $this->seed(DatabaseSeeder::class);
        $district = District::default();

        $this->assertSame($district->id, Employer::sole()->district_id);
        foreach (Merchant::all() as $merchant) {
            $this->assertSame($district->id, $merchant->district_id, $merchant->name);
            $this->assertNotNull($merchant->district_approved_at);
            $this->assertNotNull($merchant->live_at);
            $this->assertTrue($district->boundary()->contains((float) $merchant->latitude, (float) $merchant->longitude), $merchant->name);
        }
    }
}
