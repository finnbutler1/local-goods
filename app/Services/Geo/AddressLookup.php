<?php

namespace App\Services\Geo;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Looks an address up once and keeps the answer (ADR 0031).
 *
 * - A found address is never looked up again; "not found" is asked again
 *   after districts.not_found_retry_days; "unavailable" is never kept.
 * - Lookups from the public checker keep no typed text: only the SHA-256 of
 *   the normalised address and the answer (status, matched address,
 *   coordinates, MAR id), purged after 30 days by applications:purge.
 *   Lookups for an application keep their query.
 */
class AddressLookup
{
    public const ORIGIN_CHECKER = 'checker';

    public const ORIGIN_APPLICATION = 'application';

    public function __construct(private Geocoder $geocoder) {}

    public function lookup(string $address, string $origin): GeocodeResult
    {
        $hash = self::hash($address);
        $row = DB::table('address_lookups')->where('origin', $origin)->where('query_hash', $hash)->first();

        if ($row !== null && ($row->status === GeocodeResult::FOUND
            || Carbon::parse($row->looked_up_at)->gt(now()->subDays((int) config('districts.not_found_retry_days', 7))))) {
            return $row->status === GeocodeResult::FOUND
                ? GeocodeResult::found($row->matched_address, (float) $row->latitude, (float) $row->longitude, $row->mar_address_id === null ? null : (int) $row->mar_address_id)
                : GeocodeResult::notFound(is_string(DcMarGeocoder::parse($address)) ? DcMarGeocoder::parse($address) : null);
        }

        $result = $this->geocoder->geocode($address);

        if ($result->status !== GeocodeResult::UNAVAILABLE) {
            $values = [
                'query' => $origin === self::ORIGIN_CHECKER ? null : mb_substr(self::normalize($address), 0, 255),
                'status' => $result->status, 'matched_address' => $result->matchedAddress,
                'latitude' => $result->latitude, 'longitude' => $result->longitude,
                'mar_address_id' => $result->marAddressId, 'source' => 'dc_mar', 'looked_up_at' => now(),
            ];

            try {
                DB::table('address_lookups')->updateOrInsert(['origin' => $origin, 'query_hash' => $hash], $values);
            } catch (UniqueConstraintViolationException) {
                // Someone else stored the same answer a moment ago.
            }
        }

        return $result;
    }

    /** Trimmed, single-spaced, upper case: "1700  k st nw " and "1700 K St NW" are one address. */
    public static function normalize(string $address): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $address)));
    }

    public static function hash(string $address): string
    {
        return hash('sha256', self::normalize($address));
    }
}
