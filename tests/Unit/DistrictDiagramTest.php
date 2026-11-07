<?php

namespace Tests\Unit;

use App\View\Components\DistrictDiagram;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

class DistrictDiagramTest extends TestCase
{
    private function diagram(): DistrictDiagram
    {
        return new DistrictDiagram(array_map(fn ($key) => [
            'name' => DatabaseSeeder::DEMO_MERCHANTS[$key][0],
            'lat' => DatabaseSeeder::DEMO_COORDINATES[$key][0],
            'lng' => DatabaseSeeder::DEMO_COORDINATES[$key][1],
        ], array_keys(DatabaseSeeder::DEMO_MERCHANTS)), 'Farragut Grind', 'Map');
    }

    public function test_the_route_runs_only_in_transit_map_angles_through_every_stop_and_closes(): void
    {
        $points = $this->diagram()->points;
        preg_match_all('/[ML]([\d.]+) ([\d.]+)/', DistrictDiagram::route($points), $m, PREG_SET_ORDER);
        $vertices = array_map(fn ($v) => [(float) $v[1], (float) $v[2]], $m);

        for ($i = 1; $i < count($vertices); $i++) {
            $dx = abs($vertices[$i][0] - $vertices[$i - 1][0]);
            $dy = abs($vertices[$i][1] - $vertices[$i - 1][1]);
            $this->assertTrue($dx < 0.11 || $dy < 0.11 || abs($dx - $dy) < 0.11, "Segment {$i} is not horizontal, vertical or 45°");
        }
        foreach ($points as $point) {
            $this->assertContains([$point['x'], $point['y']], $vertices, $point['name']);
        }
        $this->assertSame($vertices[0], end($vertices));
    }

    public function test_every_stop_is_inside_the_drawing_and_exactly_one_is_local(): void
    {
        $diagram = $this->diagram();

        $this->assertCount(8, $diagram->points);
        $this->assertSame(['Farragut Grind'], array_column(array_filter($diagram->points, fn ($p) => $p['local']), 'name'));
        foreach ($diagram->points as $point) {
            $this->assertTrue($point['x'] > 0 && $point['x'] < 1000 && $point['y'] > 0 && $point['y'] < $diagram->map->height, $point['name']);
        }
    }

    public function test_the_loop_order_does_not_depend_on_the_input_order(): void
    {
        $points = $this->diagram()->points;

        $this->assertSame(array_column($points, 'name'), array_column(DistrictDiagram::loop(array_reverse($points)), 'name'));
    }
}
