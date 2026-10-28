<?php

namespace App\Support\Districts;

use App\Models\District;

/**
 * Draws a district as an inline SVG (ADR 0031), from the stored boundary
 * and nothing else: no map tiles, no outside requests. Longitude and
 * latitude become x and y on a flat projection, with longitude shrunk by
 * cos(latitude) so the shape isn't stretched, inside a 1000-wide viewBox.
 */
final class DistrictMap
{
    public const WIDTH = 1000;

    private const PAD = 40;

    private readonly float $minLng;

    private readonly float $maxLat;

    private readonly float $scale;

    private readonly float $shrink;

    public readonly int $height;

    /** @param  list<list<array{0: float, 1: float}>>  $rings  outer rings, [longitude, latitude] */
    public function __construct(private readonly array $rings)
    {
        $points = array_merge(...$rings);
        $lngs = array_column($points, 0);
        $lats = array_column($points, 1);
        $this->minLng = min($lngs);
        $this->maxLat = max($lats);
        $this->shrink = cos(deg2rad((min($lats) + $this->maxLat) / 2));
        $spanX = (max($lngs) - $this->minLng) * $this->shrink;
        $spanY = $this->maxLat - min($lats);
        $this->scale = (self::WIDTH - 2 * self::PAD) / max($spanX, 1e-9);
        $this->height = (int) ceil($spanY * $this->scale + 2 * self::PAD);
    }

    public static function for(District $district): self
    {
        return new self($district->outerRings());
    }

    public function viewBox(): string
    {
        return '0 0 '.self::WIDTH.' '.$this->height;
    }

    /** @return array{0: float, 1: float} x, y in the viewBox */
    public function project(float $latitude, float $longitude): array
    {
        return [
            round(self::PAD + ($longitude - $this->minLng) * $this->shrink * $this->scale, 1),
            round(self::PAD + ($this->maxLat - $latitude) * $this->scale, 1),
        ];
    }

    /** Is the point within the drawing (so a pin can be shown)? */
    public function shows(float $latitude, float $longitude): bool
    {
        [$x, $y] = $this->project($latitude, $longitude);

        return $x >= 0 && $x <= self::WIDTH && $y >= 0 && $y <= $this->height;
    }

    /** The SVG path for every ring. */
    public function path(): string
    {
        return implode(' ', array_map(function (array $ring) {
            $commands = array_map(function (array $point, int $i) {
                [$x, $y] = $this->project($point[1], $point[0]);

                return ($i === 0 ? 'M' : 'L').$x.' '.$y;
            }, $ring, array_keys($ring));

            return implode(' ', $commands).' Z';
        }, $this->rings));
    }
}
