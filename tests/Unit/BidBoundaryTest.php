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
