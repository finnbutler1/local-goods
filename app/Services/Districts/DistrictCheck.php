<?php

namespace App\Services\Districts;

use App\Models\District;
use App\Services\Geo\AddressLookup;
use App\Services\Geo\GeocodeResult;

/**
 * "Is this address in the district?" (ADR 0031): the address lookup, then
 * DistrictRules::isInside, the same point-in-polygon check that decides
 * whether a merchant may be approved. The public map checker and the
 * application eligibility check both come through here.
 */
class DistrictCheck
{
    public function __construct(private AddressLookup $lookup) {}

    public function address(District $district, string $address, string $origin): DistrictCheckResult
    {
        $result = $this->lookup->lookup($address, $origin);

        return new DistrictCheckResult(
            $result,
            $result->isFound() ? DistrictRules::isInside($district, $result->latitude, $result->longitude) : null,
            $district,
        );
    }
}
