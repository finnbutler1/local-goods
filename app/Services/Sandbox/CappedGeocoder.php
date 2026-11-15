<?php

namespace App\Services\Sandbox;

use App\Services\Geo\Geocoder;
use App\Services\Geo\GeocodeResult;

/**
 * The address lookup inside a sandbox (ADR 0045 addendum). It passes the
 * address through untouched to the real one (DC's Master Address
 * Repository, with its own short timeout); it only answers "unavailable",
 * without asking DC, once the sandbox or the day has used its lookups.
 * "Unavailable" is the existing answer for a slow or broken service: the
 * check stops before the AI is asked and nothing changes.
 *
 * Lookups already stored in the sandbox's own file are answered there and
 * never reach this (AddressLookup).
 */
class CappedGeocoder implements Geocoder
{
    public function __construct(private Geocoder $inner, private SandboxAddressBudget $budget) {}

    public function geocode(string $address): GeocodeResult
    {
        if (! $this->budget->take()) {
            return GeocodeResult::unavailable();
        }

        return $this->inner->geocode($address);
    }
}
