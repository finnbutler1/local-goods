<?php

namespace Tests\Unit;

use App\Support\Districts\DistrictMap;
use PHPUnit\Framework\TestCase;

class DistrictMapTest extends TestCase
{
    /** A 0.01° square at DC's latitude. */
    private function square(): DistrictMap
    {
        return new DistrictMap([[[-77.04, 38.90], [-77.03, 38.90], [-77.03, 38.91], [-77.04, 38.91], [-77.04, 38.90]]]);
    }

    public function test_corners_land_inside_the_padding_and_north_is_up(): void
    {
        $map = $this->square();

        $this->assertSame([40.0, 40.0], $map->project(38.91, -77.04), 'north-west corner at the top left');
        [$x, $y] = $map->project(38.90, -77.03);
        $this->assertSame(960.0, $x, 'east edge at the right');
        $this->assertEqualsWithDelta($map->height - 40, $y, 1, 'south edge at the bottom');
    }

    public function test_the_shape_is_not_stretched(): void
    {
        // A degree of longitude is cos(38.9°) ≈ 0.78 of a degree of latitude, so the square is taller than wide.
        $map = $this->square();

        $this->assertEqualsWithDelta(920 / cos(deg2rad(38.905)), $map->height - 80, 2);
        $this->assertSame('0 0 1000 '.$map->height, $map->viewBox());
    }

    public function test_the_path_closes_each_ring(): void
    {
        $path = $this->square()->path();

        $this->assertStringStartsWith('M40 ', $path);
        $this->assertStringEndsWith(' Z', $path);
        $this->assertSame(4, substr_count($path, 'L'));
    }

    public function test_points_far_away_are_not_shown(): void
    {
        $this->assertTrue($this->square()->shows(38.905, -77.035));
        $this->assertFalse($this->square()->shows(38.8973, -77.0063), 'Union Station is off the drawing');
    }
}
