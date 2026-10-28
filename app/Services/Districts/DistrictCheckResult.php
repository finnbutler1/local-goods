<?php

namespace App\Services\Districts;

use App\Models\District;
use App\Services\Geo\GeocodeResult;

/** The answer to "is this address in the district?", with a plain explanation. */
final class DistrictCheckResult
{
    public function __construct(
        public readonly GeocodeResult $lookup,
        /** true inside, false outside, null when we couldn't find the address. */
        public readonly ?bool $inside,
        public readonly District $district,
    ) {}

    public function headline(): string
    {
        return match (true) {
            $this->inside === true => "Inside the {$this->district->name}.",
            $this->inside === false => "Outside the {$this->district->name}.",
            $this->lookup->status === GeocodeResult::UNAVAILABLE => 'We couldn\'t check that address right now.',
            default => 'We couldn\'t find that address.',
        };
    }

    public function explanation(): string
    {
        $l = $this->lookup;

        return match (true) {
            $this->inside !== null => sprintf(
                'DC\'s Master Address Repository matched "%s" at %.5f, %.5f, and that point is %s the boundary DC publishes for the %s. '
                .'This is the same check Local Goods runs before a BID can approve a merchant.',
                $l->matchedAddress, $l->latitude, $l->longitude, $this->inside ? 'inside' : 'outside', $this->district->name,
            ),
            $l->status === GeocodeResult::UNAVAILABLE => 'DC\'s address service didn\'t answer. Nothing was decided: try again in a minute.',
            default => ($l->hint ? $l->hint.' ' : '').'Only addresses in DC\'s Master Address Repository can be checked, written like "1700 K St NW".',
        };
    }
}
