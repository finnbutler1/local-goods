<?php

namespace App\Services\Geo;

/**
 * Turns a DC street address into a point (ADR 0031). The real one is
 * DcMarGeocoder; tests use Http::fake.
 */
interface Geocoder
{
    public function geocode(string $address): GeocodeResult;
}
