<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DC's Master Address Repository (MAR), through the address points layer
 * DC GIS publishes (config districts.mar_address_points_url). Keyless: the
 * older keyless MAR verifier has been retired and MAR 2's API needs a key.
 *
 * The layer matches exact addresses, so the typed address is put in MAR's
 * form first: "1700 K St NW, Washington DC" becomes "1700 K STREET NW".
 * Only that address text is sent: no cookies, no user or session details.
 * Any network error or odd reply is "unavailable", never a match.
 */
class DcMarGeocoder implements Geocoder
{
    public const NEEDS_QUADRANT = 'DC addresses end in a quadrant: add NW, NE, SW or SE (for example "1700 K St NW").';

    public const NEEDS_TYPE = 'Add the street type (St, Ave, Pl…) so we know which street you mean.';

    public const NEEDS_NUMBER = 'Start with the building number (for example "1700 K St NW").';

    /** Street types as people type them => as MAR spells them. */
    private const TYPES = [
        'ST' => 'STREET', 'STREET' => 'STREET', 'AVE' => 'AVENUE', 'AV' => 'AVENUE', 'AVENUE' => 'AVENUE',
        'PL' => 'PLACE', 'PLACE' => 'PLACE', 'CIR' => 'CIRCLE', 'CIRCLE' => 'CIRCLE', 'RD' => 'ROAD', 'ROAD' => 'ROAD',
        'DR' => 'DRIVE', 'DRIVE' => 'DRIVE', 'CT' => 'COURT', 'COURT' => 'COURT', 'TER' => 'TERRACE', 'TERRACE' => 'TERRACE',
        'BLVD' => 'BOULEVARD', 'BOULEVARD' => 'BOULEVARD', 'PKWY' => 'PARKWAY', 'PARKWAY' => 'PARKWAY',
        'SQ' => 'SQUARE', 'SQUARE' => 'SQUARE', 'ALY' => 'ALLEY', 'ALLEY' => 'ALLEY', 'WAY' => 'WAY', 'LN' => 'LANE', 'LANE' => 'LANE',
    ];

    public function geocode(string $address): GeocodeResult
    {
        $parts = self::parse($address);
        if (is_string($parts)) {
            return GeocodeResult::notFound($parts);
        }

        [$number, $street, $type, $quadrant] = $parts;
        if ($type === null && ! ctype_digit($number)) {
            return GeocodeResult::notFound(self::NEEDS_TYPE);
        }
        $where = $type !== null
            ? "ADDRESS='".self::quote("{$number} {$street} {$type} {$quadrant}")."'"
            : 'ADDRESS_NUMBER='.(int) $number." AND STREET_NAME='".self::quote($street)."' AND QUADRANT='{$quadrant}'";

        try {
            $response = Http::timeout((int) config('districts.mar_timeout_seconds', 5))->withoutRedirecting()->get(config('districts.mar_address_points_url'), [
                'where' => $where." AND STATUS='ACTIVE'",
                'outFields' => 'ADDRESS,MAR_ID,LATITUDE,LONGITUDE',
                'returnGeometry' => 'false',
                'resultRecordCount' => 2,
                'f' => 'json',
            ]);
        } catch (Throwable) {
            return GeocodeResult::unavailable();
        }

        $features = $response->successful() ? $response->json('features') : null;
        if (! is_array($features) || $response->json('error') !== null) {
            return GeocodeResult::unavailable();
        }

        // No match, or (with no street type) more than one street of that name: ask for the full address.
        if (count($features) !== 1) {
            return GeocodeResult::notFound($features === [] ? null : self::NEEDS_TYPE);
        }

        $a = $features[0]['attributes'] ?? [];
        if (! is_numeric($a['LATITUDE'] ?? null) || ! is_numeric($a['LONGITUDE'] ?? null) || ! is_string($a['ADDRESS'] ?? null)) {
            return GeocodeResult::unavailable();
        }

        return GeocodeResult::found($a['ADDRESS'], (float) $a['LATITUDE'], (float) $a['LONGITUDE'], is_numeric($a['MAR_ID'] ?? null) ? (int) $a['MAR_ID'] : null);
    }

    /**
     * "1700 K St NW, Washington, DC 20006" => ['1700', 'K', 'STREET', 'NW'];
     * a string is the reason it can't be read.
     *
     * @return array{0: string, 1: string, 2: ?string, 3: string}|string
     */
    public static function parse(string $address): array|string
    {
        $text = mb_strtoupper(AddressLookup::normalize($address));
        $text = (string) preg_replace('/,.*$/u', '', $text);           // drop ", Washington, DC 20006"
        $text = (string) preg_replace('/[^A-Z0-9 \'-]/', ' ', $text);  // dots, #, etc.
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === [] || ! preg_match('/^\d{1,5}[A-Z]?$/', $words[0])) {
            return self::NEEDS_NUMBER;
        }

        $quadrantAt = null;
        foreach ($words as $i => $word) {
            if ($i > 1 && in_array($word, ['NW', 'NE', 'SW', 'SE'], true)) {
                $quadrantAt = $i;
                break;
            }
        }
        if ($quadrantAt === null) {
            return self::NEEDS_QUADRANT;
        }

        $street = array_slice($words, 1, $quadrantAt - 1);
        $type = null;
        if (count($street) > 1 && isset(self::TYPES[end($street)])) {
            $type = self::TYPES[array_pop($street)];
        }

        return [$words[0], implode(' ', $street), $type, $words[$quadrantAt]];
    }

    private static function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
