<?php

namespace Tests\Concerns;

use App\Models\District;
use Database\Seeders\DatabaseSeeder;

/**
 * Columns for a test merchant that the seeded (district program) employee
 * may spend credits at (ADR 0031): in the Golden Triangle, at a point inside
 * it, approved and live.
 */
trait PlacesMerchantsInTheDistrict
{
    /** @return array<string, mixed> */
    protected function inTheDistrict(): array
    {
        [$latitude, $longitude] = DatabaseSeeder::DEMO_COORDINATES['cafe'];

        return [
            'district_id' => District::default()->id, 'latitude' => $latitude, 'longitude' => $longitude,
            'district_approved_at' => now(), 'live_at' => now(),
        ];
    }
}
