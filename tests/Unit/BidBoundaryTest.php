<?php

namespace Tests\Unit;

use App\Services\Eligibility\BidBoundary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BidBoundaryTest extends TestCase
{
    /** A 10 x 10 square (longitude, latitude) with a 2 x 2 hole in the middle. */
    private function square(): BidBoundary
    {
        return new BidBoundary([[
            [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]],
            [[4, 4], [6, 4], [6, 6], [4, 6], [4, 4]],
        ]]);
    }

    public function test_points_inside_outside_and_in_a_hole(): void
    {
        $this->assertTrue($this->square()->contains(1, 1));
        $this->assertTrue($this->square()->contains(9.9, 0.1));
        $this->assertFalse($this->square()->contains(11, 5), 'east of the square');
        $this->assertFalse($this->square()->contains(5, -1), 'west of the square');
        $this->assertFalse($this->square()->contains(5, 5), 'inside the hole');
    }

    public function test_reads_geojson_feature_collections_and_multipolygons(): void
    {
        $collection = json_encode(['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'geometry' => [
            'type' => 'MultiPolygon',
            'coordinates' => [[[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]], [[[5, 5], [6, 5], [6, 6], [5, 6], [5, 5]]]],
        ]]]]);
        $boundary = BidBoundary::fromGeoJson($collection);

        $this->assertTrue($boundary->contains(0.5, 0.5));
        $this->assertTrue($boundary->contains(5.5, 5.5));
        $this->assertFalse($boundary->contains(3, 3));
    }

    /** An L shape: the notch (top right) is outside although it is inside the bounding box. */
    private function concave(): BidBoundary
    {
        return new BidBoundary([[[[0, 0], [10, 0], [10, 4], [4, 4], [4, 10], [0, 10], [0, 0]]]]);
    }

    public function test_a_concave_notch_is_outside(): void
    {
        $this->assertTrue($this->concave()->contains(2, 8), 'in the upright of the L');
        $this->assertTrue($this->concave()->contains(2, 2), 'in the corner');
        $this->assertFalse($this->concave()->contains(8, 8), 'in the notch');
    }

    public function test_points_just_either_side_of_an_edge(): void
    {
        $this->assertTrue($this->square()->contains(5, 9.999999));
        $this->assertFalse($this->square()->contains(5, 10.000001));
        $this->assertTrue($this->square()->contains(0.000001, 5));
        $this->assertFalse($this->square()->contains(-0.000001, 5));
    }

    public function test_a_ray_through_a_vertex_counts_once(): void
    {
        // A diamond: a ray east from (y=5) passes exactly through the right-hand vertex (10, 5).
        $diamond = new BidBoundary([[[[5, 0], [10, 5], [5, 10], [0, 5], [5, 0]]]]);

        $this->assertTrue($diamond->contains(5, 5));
        $this->assertTrue($diamond->contains(5, 1));
        $this->assertFalse($diamond->contains(5, -1));
        $this->assertFalse($diamond->contains(9.5, 9.5), 'beside the top vertex');
    }

    public function test_impossible_coordinates_are_never_inside(): void
    {
        $world = new BidBoundary([[[[-180, -90], [180, -90], [180, 90], [-180, 90], [-180, -90]]]]);

        $this->assertTrue($world->contains(0, 0));
        $this->assertFalse($world->contains(NAN, 0));
        $this->assertFalse($world->contains(0, INF));
        $this->assertFalse($world->contains(91, 0));
        $this->assertFalse($world->contains(0, -181));
    }

    public function test_an_empty_boundary_or_a_point_geometry_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BidBoundary::fromGeoJson(json_encode(['type' => 'Point', 'coordinates' => [0, 0]]));
    }

    /**
     * Landmarks around the real Golden Triangle BID boundary in
     * data/sources/golden_triangle_bid.geojson.
     *
     * @return array<string, array{float, float, bool}>
     */
    public static function landmarks(): array
    {
        return [
            'Farragut Square' => [38.9020, -77.0390, true],
            '17th and K St NW' => [38.9024, -77.0386, true],
            'Connecticut Ave at L St NW' => [38.9037, -77.0405, true],
            'Union Station' => [38.8973, -77.0063, false],
            'Georgetown, M St and Wisconsin Ave' => [38.9050, -77.0627, false],
            'Dupont Circle fountain' => [38.9096, -77.0434, false],
            'The White House' => [38.8977, -77.0365, false],
            'Capitol Hill' => [38.8899, -77.0091, false],
        ];
    }

    #[DataProvider('landmarks')]
    public function test_real_boundary_puts_landmarks_on_the_right_side(float $lat, float $lng, bool $inside): void
    {
        $boundary = BidBoundary::fromFile(dirname(__DIR__, 2).'/data/sources/golden_triangle_bid.geojson');

        $this->assertSame($inside, $boundary->contains($lat, $lng));
    }
}
