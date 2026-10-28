<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes DC's Master Address Repository address points layer (ADR 0031):
 * the addresses given are found, anything else isn't, and no other HTTP
 * request may leave the test.
 */
trait FakesDcAddresses
{
    /** MAR-spelled address => [latitude, longitude] */
    protected const DC_ADDRESSES = [
        '1700 K STREET NW' => [38.90217607, -77.0398332],        // inside the Golden Triangle
        '1100 CONNECTICUT AVENUE NW' => [38.904052, -77.040644], // inside
        '50 MASSACHUSETTS AVENUE NE' => [38.89761945, -77.00617579], // Union Station, outside
    ];

    /** Set by fakeDcAddresses(down: true); a later call changes it, since the first fake stays registered. */
    protected bool $dcAddressesDown = false;

    protected bool $dcAddressesFaked = false;

    protected function fakeDcAddresses(bool $down = false): void
    {
        $this->dcAddressesDown = $down;
        if ($this->dcAddressesFaked) {
            return;
        }
        $this->dcAddressesFaked = true;

        Http::preventStrayRequests();
        Http::fake([
            parse_url(config('districts.mar_address_points_url'), PHP_URL_HOST).'/*' => function (Request $request) {
                if ($this->dcAddressesDown) {
                    return Http::response('Service Unavailable', 503);
                }

                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $features = [];
                foreach (self::DC_ADDRESSES as $address => [$lat, $lng]) {
                    [$number, $rest] = explode(' ', $address, 2);
                    $street = preg_replace('/ (STREET|AVENUE) (NW|NE|SW|SE)$/', '', $rest);
                    $quadrant = substr($address, -2);
                    if (str_contains($query['where'] ?? '', "ADDRESS='{$address}'")
                        || str_contains($query['where'] ?? '', "ADDRESS_NUMBER={$number} AND STREET_NAME='{$street}' AND QUADRANT='{$quadrant}'")) {
                        $features[] = ['attributes' => ['ADDRESS' => $address, 'MAR_ID' => 240000 + count($features), 'LATITUDE' => $lat, 'LONGITUDE' => $lng]];
                    }
                }

                return Http::response(['features' => $features]);
            },
        ]);
    }
}
