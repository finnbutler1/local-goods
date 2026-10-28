<?php

namespace App\Services\Eligibility;

use InvalidArgumentException;

/**
 * The BID boundary as polygons of [longitude, latitude] points, with a
 * point-in-polygon test (ray casting). Holes are respected: a point inside
 * a hole is outside the boundary.
 */
class BidBoundary
{
    /**
     * @param  list<list<list<array{0: float, 1: float}>>>  $polygons  each polygon: outer ring, then holes
     */
    public function __construct(private array $polygons)
    {
        if ($polygons === []) {
            throw new InvalidArgumentException('The boundary has no polygons.');
        }
    }

    public static function fromGeoJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $geometry = $data['features'][0]['geometry'] ?? $data['geometry'] ?? $data;

        return new self(match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default => throw new InvalidArgumentException('Expected a GeoJSON Polygon or MultiPolygon.'),
        });
    }

    public static function fromFile(string $path): self
    {
        return self::fromGeoJson((string) file_get_contents($path));
    }

    /**
     * Not-a-number, infinite or off-the-globe coordinates are never inside.
     * A point exactly on an edge may land on either side (half-open ray
     * casting); real addresses are never that precise.
     */
    public function contains(float $latitude, float $longitude): bool
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            return false;
        }

        foreach ($this->polygons as $polygon) {
            if ($this->inRing($polygon[0], $longitude, $latitude)) {
                foreach (array_slice($polygon, 1) as $hole) {
                    if ($this->inRing($hole, $longitude, $latitude)) {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * ArcGIS "rings" for the same polygons, to use as a server-side filter.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    public function rings(): array
    {
        return array_merge(...$this->polygons);
    }

    /**
     * Each polygon's outer ring, without its holes (for drawing a map).
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    public function outerRings(): array
    {
        return array_map(fn (array $polygon) => $polygon[0], $this->polygons);
    }

    /**
     * Counts how many ring edges a ray going east from the point crosses; odd means inside.
     *
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private function inRing(array $ring, float $x, float $y): bool
    {
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];

            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
