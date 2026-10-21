<?php

namespace App\Services\Eligibility;

/**
 * Turns DC business license records into a list of real, consumer-facing
 * businesses inside the BID. A license is kept only if all three agree that
 * it is inside: DC's own server-side spatial query (done by the caller), the
 * license's own BUSINESSIMPROVEMENTDISTRICT field, and our point-in-polygon
 * test on its map point. Only business names are kept: a license whose only
 * name looks like a person's (a sole trader) is left out, and owner and
 * agent names are never requested. The legal entity name is read only to
 * spot those sole traders: it is never written to a file, and the source
 * link asks DC for the trade name only.
 */
class MerchantCandidateBuilder
{
    public const COLUMNS = ['candidate_id', 'name', 'address', 'category', 'source_url'];

    private const FOOD = ['Restaurant', 'Delicatessen', 'Bakery'];

    private const RETAIL_ACTIVITIES = ['Patent Medicine', 'Cigarette Sales (Retail)'];

    /** "General Business" covers offices too, so only names that say what they sell are kept. */
    private const NAME_CATEGORIES = [
        'fitness' => '/\b(fitness|gym|yoga|pilates|barre|cycle|cycling|boxing|crossfit|solidcore|orangetheory|f45|bootcamp)\b/i',
        'salon' => '/\b(salon|barber|barbershop|nails?|brows?|wax|waxing|blowout|spa|hair)\b/i',
        'retail' => '/\b(books?|bookstore|florist|flowers?|jewel(l)?ers?|jewelry|optical|optician|eyewear|apparel|clothing|clothiers?|boutique|shoes?|tailor(s|ing)?|wine|spirits|liquors?|pharmacy|drug|market|gifts?|stationery|cleaners|outfitters|menswear|bicycles?|bikes?|cigars?)\b/i',
    ];

    /** Shops that sell cigarettes or over-the-counter medicine; bars and restaurants hold those licenses too. */
    private const STORE = '/\b(pharmacy|drug|rx|market|mart|liquors?|wine|smoke|emporium|traders|convenience|newsstand|cvs|walgreens|7-?eleven|wawa)\b/i';

    private const CAFE = '/\b(caf[eé]|coffee|espresso|tea|bakery|bagels?|donuts?|doughnuts?|juice|boba|bubble)\b/i';

    private const BUSINESS_SUFFIX = '/\b(L\.?L\.?C|INC|CORP|CORPORATION|CO|COMPANY|L\.?P|L\.?L\.?P|LTD|P\.?L\.?L\.?C|PARTNERSHIP|GROUP|HOLDINGS|ENTERPRISES?|ASSOCIATES|VENTURES|RESTAURANT|CAFE|SALON)\b\.?/i';

    public array $report = [];

    /**
     * @param  array<string, string>  $overrides  normalised name => category, for licenses that name the wrong kind of business
     */
    public function __construct(private array $overrides = []) {}

    /**
     * @param  list<array{attributes: array<string, mixed>, geometry?: array{x: float, y: float}}>  $licenses
     * @param  array<string, string>  $existingIds  "name|address" => candidate_id from an earlier build
     * @return array{candidates: list<array<string, string>>, points: list<array<string, string>>, activity: list<array<string, string>>}
     */
    public function build(array $licenses, BidBoundary $boundary, string $bidName, string $licensesUrl, array $existingIds = []): array
    {
        $this->report = [
            'licenses_returned' => count($licenses),
            'not_active' => 0,
            'bid_field_disagrees' => 0,
            'outside_our_polygon_check' => 0,
            'not_consumer_facing' => 0,
            'only_a_personal_name' => 0,
            'duplicate_locations_merged' => 0,
        ];
        $byKey = [];

        foreach ($licenses as $license) {
            $a = $license['attributes'];
            $point = $license['geometry'] ?? null;

            if (($a['LICENSESTATUS'] ?? null) !== 'Active') {
                $this->report['not_active']++;

                continue;
            }
            if (($a['BUSINESSIMPROVEMENTDISTRICT'] ?? null) !== $bidName) {
                $this->report['bid_field_disagrees']++;

                continue;
            }
            if ($point === null || ! $boundary->contains((float) $point['y'], (float) $point['x'])) {
                $this->report['outside_our_polygon_check']++;

                continue;
            }

            $name = $this->businessName($a);
            [$category, $rank] = $this->category($a, $name ?? '');

            if ($category === null) {
                $this->report['not_consumer_facing']++;

                continue;
            }
            if ($name === null) {
                $this->report['only_a_personal_name']++;

                continue;
            }

            $category = $this->overrides[$this->normalise($name)] ?? $category;
            $address = $this->address((string) $a['PREMISEADDRESS']);
            $key = $this->similarKey($byKey, $this->key($name, $address));

            if (isset($byKey[$key])) {
                $this->report['duplicate_locations_merged']++;
                if ($rank >= $byKey[$key]['rank']) {
                    continue;
                }
            }

            $byKey[$key] = [
                'rank' => $rank,
                'name' => $name,
                'address' => $address,
                'category' => $category,
                'source_url' => $this->sourceUrl($licensesUrl, (string) $a['CUSTOMERNUMBER']),
                'license' => (string) $a['CUSTOMERNUMBER'],
                'business_activity' => trim((string) ($a['BUSINESSACTIVITY'] ?? '')),
                'lat' => sprintf('%.6f', $point['y']),
                'lng' => sprintf('%.6f', $point['x']),
            ];
        }

        return $this->number($byKey, $existingIds);
    }

    /**
     * Keeps earlier ids so a rebuild never renumbers labeled candidates; new
     * businesses get the next free numbers, in name order.
     *
     * @param  array<string, array<string, mixed>>  $byKey
     * @param  array<string, string>  $existingIds
     * @return array{candidates: list<array<string, string>>, points: list<array<string, string>>, activity: list<array<string, string>>}
     */
    private function number(array $byKey, array $existingIds): array
    {
        uasort($byKey, fn ($a, $b) => [$this->normalise($a['name']), $a['address']] <=> [$this->normalise($b['name']), $b['address']]);
        $existingByKey = [];
        foreach ($existingIds as $key => $id) {
            $existingByKey[$this->rekey($key)] = $id;
        }
        $next = 1 + max([0, ...array_map(fn ($id) => (int) substr($id, 3), $existingIds)]);

        $rows = [];
        foreach ($byKey as $key => $row) {
            $row['candidate_id'] = $existingByKey[$key] ?? sprintf('MC-%03d', $next++);
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => strcmp($a['candidate_id'], $b['candidate_id']));

        return [
            'candidates' => array_map(fn ($r) => array_intersect_key($r, array_flip(self::COLUMNS)) + array_fill_keys(self::COLUMNS, ''), $rows),
            'points' => array_map(fn ($r) => ['candidate_id' => $r['candidate_id'], 'lat' => $r['lat'], 'lng' => $r['lng']], $rows),
            'activity' => array_map(fn ($r) => ['candidate_id' => $r['candidate_id'], 'business_activity' => $r['business_activity']], $rows),
        ];
    }

    /**
     * One business at one street address: store numbers, company words and
     * suite numbers are ignored, so two licenses for the same shop merge.
     */
    public function key(string $name, string $address): string
    {
        $name = (string) preg_replace('/(#\s*\d+|\bstore\s+\d+|\s\d+$)/i', '', $name);

        return $this->normalise($name).'|'.$this->normalise(explode(',', $address)[0]);
    }

    /**
     * The key of a business already listed at the same street address under
     * a nearly identical name (a typo or a plural in one license), else $key.
     *
     * @param  array<string, mixed>  $byKey
     */
    private function similarKey(array $byKey, string $key): string
    {
        [$name, $street] = explode('|', $key, 2);

        foreach (array_keys($byKey) as $existing) {
            [$otherName, $otherStreet] = explode('|', $existing, 2);
            similar_text($name, $otherName, $percent);

            if ($street === $otherStreet && $percent >= 85) {
                return $existing;
            }
        }

        return $key;
    }

    private function rekey(string $key): string
    {
        [$name, $address] = explode('|', $key, 2) + ['', ''];

        return $this->key($name, $address);
    }

    /**
     * The trade name if there is a real one; otherwise the legal name, but
     * only if it is clearly a company. A name that is only a person's (no
     * company word, and the same as the licensee) is never used.
     *
     * @param  array<string, mixed>  $a
     */
    private function businessName(array $a): ?string
    {
        $trade = trim((string) ($a['ENTITYTRADENAME'] ?? ''));
        $entity = trim((string) ($a['ENTITYNAME'] ?? ''));
        $isCompany = (bool) preg_match(self::BUSINESS_SUFFIX, $entity);

        if ($trade !== '' && ! in_array(strtoupper($trade), ['N/A', 'NA', 'NONE', 'NULL', '-'], true)) {
            if (! $isCompany && $this->normalise($trade) === $this->normalise($entity)) {
                return null;
            }

            return $this->tidy($trade);
        }

        return $isCompany ? $this->tidy($entity) : null;
    }

    /**
     * Our category, and a rank for choosing between two licenses at one
     * place (a food or grooming license says more than a general one).
     *
     * @param  array<string, mixed>  $a
     * @return array{0: ?string, 1: int}
     */
    private function category(array $a, string $name): array
    {
        $type = $a['CATEGORYSERVICETYPE'] ?? null;
        $activity = $a['BUSINESSACTIVITY'] ?? null;

        if ($type === 'Food Services' && in_array($activity, self::FOOD, true)) {
            return [preg_match(self::CAFE, $name) ? 'cafe' : 'restaurant', 1];
        }
        if ($type === 'Beauty and Grooming Services') {
            return ['salon', 1];
        }
        if ($type === 'General Sales and Services' && in_array($activity, self::RETAIL_ACTIVITIES, true) && preg_match(self::STORE, $name)) {
            return ['retail', 2];
        }
        if ($type === 'General Sales and Services' && $activity === 'General Business') {
            foreach (self::NAME_CATEGORIES as $category => $pattern) {
                if (preg_match($pattern, $name)) {
                    return [$category, 3];
                }
            }
        }

        return [null, 9];
    }

    private function tidy(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    private function address(string $premise): string
    {
        return trim((string) preg_replace('/,\s*USA$/i', '', trim($premise)));
    }

    private function normalise(string $value): string
    {
        $value = strtolower((string) preg_replace(self::BUSINESS_SUFFIX, '', $value));

        return (string) preg_replace('/[^a-z0-9]+/', '', $value);
    }

    private function sourceUrl(string $licensesUrl, string $licenseNumber): string
    {
        return $licensesUrl.'?'.http_build_query([
            'where' => "CUSTOMERNUMBER='{$licenseNumber}'",
            'outFields' => 'ENTITYTRADENAME,PREMISEADDRESS,BUSINESSACTIVITY,LICENSESTATUS,BUSINESSIMPROVEMENTDISTRICT',
            'f' => 'html',
        ]);
    }
}
