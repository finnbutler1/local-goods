<?php

namespace App\View\Components;

use App\Services\Eligibility\BidBoundary;
use App\Support\Districts\DistrictMap;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The district as a transit diagram (ADR 0038): the stored boundary from
 * data/sources/golden_triangle_bid.geojson, four main streets and the given
 * stops on one closed teal line, drawn with the same projection as
 * <x-district-map> (DistrictMap). Display only: it reads the boundary file,
 * never the database or the network.
 */
class DistrictDiagram extends Component
{
    /** Labels may run past the boundary's padding, so the drawing is a little wider. */
    public const BLEED = 20;

    /**
     * The four streets that frame the district, as [latitude, longitude]
     * points, each with where its label sits (on the first segment).
     */
    public const STREETS = [
        ['K ST NW', [[38.902350, -77.048924], [38.902350, -77.036275]], [38.902508, -77.048660], 'start'],
        ['17TH ST NW', [[38.909500, -77.039449], [38.897197, -77.039449]], [38.906424, -77.039253], 'middle'],
        ['CONNECTICUT AVE NW', [[38.909600, -77.043399], [38.902400, -77.039449], [38.900068, -77.036934]], [38.907531, -77.041967], 'middle'],
        ['PENNSYLVANIA AVE NW', [[38.902098, -77.048924], [38.898925, -77.039449], [38.898925, -77.036275]], [38.900785, -77.045893], 'middle'],
    ];

    public readonly DistrictMap $map;

    /** @var list<array{name: string, lines: list<string>, x: float, y: float, side: string, local: bool}> */
    public readonly array $points;

    /**
     * @param  list<array{name: string, lat: float, lng: float, lines?: list<string>, side?: string}>  $stops
     *                                                                                                         side: right, left, above, below, below-left or below-right (where the label goes)
     */
    public function __construct(public array $stops, public string $local, public string $title)
    {
        $this->map = new DistrictMap(BidBoundary::fromFile(config('districts.golden_triangle.boundary_path'))->outerRings());
        $this->points = self::loop(array_map(function (array $stop) {
            [$x, $y] = $this->map->project($stop['lat'], $stop['lng']);

            return ['name' => $stop['name'], 'lines' => $stop['lines'] ?? [$stop['name']], 'x' => $x, 'y' => $y,
                'side' => $stop['side'] ?? 'right', 'local' => $stop['name'] === $this->local];
        }, $stops));
    }

    /**
     * The stops in order round the line: by angle about their centre, which
     * makes one closed loop that never crosses itself, starting at the top.
     *
     * @template T of array{x: float, y: float}
     *
     * @param  list<T>  $points
     * @return list<T>
     */
    public static function loop(array $points): array
    {
        if ($points === []) {
            return [];
        }
        $cx = array_sum(array_column($points, 'x')) / count($points);
        $cy = array_sum(array_column($points, 'y')) / count($points);
        $angle = fn (array $p) => fmod(atan2($p['y'] - $cy, $p['x'] - $cx) + 2.5 * M_PI, 2 * M_PI);
        usort($points, fn ($a, $b) => $angle($b) <=> $angle($a));

        return $points;
    }

    /**
     * The line through every point and back to the first, in the transit
     * map's own angles: from each stop a 45° run, then a straight one.
     *
     * @param  list<array{x: float, y: float}>  $points
     */
    public static function route(array $points): string
    {
        if ($points === []) {
            return '';
        }
        $d = 'M'.$points[0]['x'].' '.$points[0]['y'];
        foreach ([...array_slice($points, 1), $points[0]] as $i => $to) {
            $from = $points[$i];
            $dx = $to['x'] - $from['x'];
            $dy = $to['y'] - $from['y'];
            $run = min(abs($dx), abs($dy));
            $d .= ' L'.round($from['x'] + $run * ($dx <=> 0), 1).' '.round($from['y'] + $run * ($dy <=> 0), 1)
                .' L'.$to['x'].' '.$to['y'];
        }

        return $d;
    }

    /** @return list<string> one path per street */
    public function streets(): array
    {
        return array_map(fn (array $street) => 'M'.implode(' L', array_map(
            fn (array $point) => implode(' ', $this->map->project(...$point)), $street[1])), self::STREETS);
    }

    /** @return list<array{text: string, x: float, y: float, angle: float, anchor: string}> */
    public function streetLabels(): array
    {
        return array_map(function (array $street) {
            [[$x1, $y1], [$x2, $y2]] = array_map(fn (array $p) => $this->map->project(...$p), array_slice($street[1], 0, 2));
            [$x, $y] = $this->map->project(...$street[2]);

            return ['text' => $street[0], 'x' => $x, 'y' => $y, 'angle' => round(rad2deg(atan2($y2 - $y1, $x2 - $x1)), 2), 'anchor' => $street[3]];
        }, self::STREETS);
    }

    /** Where a stop's label starts: x, y, text-anchor. */
    public static function labelAt(array $point): array
    {
        [$x, $y, $r] = [$point['x'], $point['y'], $point['local'] ? 22 : 15];
        $lines = count($point['lines']);

        return match ($point['side']) {
            'left' => [$x - $r - 11, $y + 8, 'end'],
            'above' => [$x, $y - $r - 25 - 26 * ($lines - 1), 'middle'],
            'below' => [$x, $y + $r + 36, 'middle'],
            'below-left' => [$x - $r - 11, $y + $r + 27, 'end'],
            'below-right' => [$x + $r + 9, $y + $r + 29, 'start'],
            default => [$x + $r + 11, $y + 8, 'start'],
        };
    }

    public function viewBox(): string
    {
        return -self::BLEED.' 0 '.(DistrictMap::WIDTH + 4 * self::BLEED).' '.$this->map->height;
    }

    public function render(): View
    {
        return view('components.district-diagram');
    }
}
