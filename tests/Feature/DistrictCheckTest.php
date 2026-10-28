<?php

namespace Tests\Feature;

use App\Models\District;
use App\Services\Districts\DistrictCheck;
use App\Services\Geo\AddressLookup;
use App\Services\Geo\DcMarGeocoder;
use App\Services\Geo\GeocodeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesDcAddresses;
use Tests\TestCase;

/**
 * Address lookups (ADR 0031): DC's Master Address Repository, asked once
 * per address, the answer judged by the same point-in-polygon rule that
 * approves merchants. A checker lookup keeps no typed text.
 */
class DistrictCheckTest extends TestCase
{
    use FakesDcAddresses;
    use RefreshDatabase;

    private function check(string $address, string $origin = AddressLookup::ORIGIN_CHECKER)
    {
        return app(DistrictCheck::class)->address(District::default(), $address, $origin);
    }

    public function test_an_address_inside_and_one_outside(): void
    {
        $this->fakeDcAddresses();

        $inside = $this->check('1700 K St NW, Washington, DC 20006');
        $this->assertTrue($inside->inside);
        $this->assertSame('Inside the Golden Triangle BID.', $inside->headline());
        $this->assertStringContainsString('"1700 K STREET NW" at 38.90218, -77.03983', $inside->explanation());
        $this->assertStringContainsString('same check Local Goods runs before a BID can approve a merchant', $inside->explanation());

        $outside = $this->check('50 Massachusetts Ave NE');
        $this->assertFalse($outside->inside);
        $this->assertSame('Outside the Golden Triangle BID.', $outside->headline());
    }

    public function test_addresses_are_read_the_way_people_type_them(): void
    {
        $this->assertSame(['1700', 'K', 'STREET', 'NW'], DcMarGeocoder::parse(' 1700  k st. nw, Washington DC 20006'));
        $this->assertSame(['1100', 'CONNECTICUT', 'AVENUE', 'NW'], DcMarGeocoder::parse('1100 Connecticut Ave NW'));
        $this->assertSame(['1150', '17TH', 'STREET', 'NW'], DcMarGeocoder::parse('1150 17th Street NW'));
        $this->assertSame(['1700', 'K', null, 'NW'], DcMarGeocoder::parse('1700 K NW'));
        $this->assertSame(DcMarGeocoder::NEEDS_QUADRANT, DcMarGeocoder::parse('1700 K St'));
        $this->assertSame(DcMarGeocoder::NEEDS_NUMBER, DcMarGeocoder::parse('K St NW'));
        $this->assertSame(['1', "O'BRIEN", 'COURT', 'SE'], DcMarGeocoder::parse("1 O'Brien Ct SE"));
    }

    public function test_quotes_in_an_address_cannot_change_the_query(): void
    {
        $this->fakeDcAddresses();
        $this->check("1 X' OR '1'='1 St NW");

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            // One string literal, every quote inside it doubled, then only our own condition.
            return preg_match("/^ADDRESS='(?:[^']|'')*' AND STATUS='ACTIVE'$/", $query['where']) === 1
                && str_contains($query['where'], "X'' OR ''1''");
        });
    }

    public function test_an_unknown_address_is_not_found_and_never_inside(): void
    {
        $this->fakeDcAddresses();

        $result = $this->check('1 Nowhere St NW');

        $this->assertNull($result->inside);
        $this->assertSame(GeocodeResult::NOT_FOUND, $result->lookup->status);
        $this->assertSame('We couldn\'t find that address.', $result->headline());
    }

    public function test_when_the_service_is_down_nothing_is_decided_or_kept(): void
    {
        $this->fakeDcAddresses(down: true);

        $result = $this->check('1700 K St NW');

        $this->assertNull($result->inside);
        $this->assertSame(GeocodeResult::UNAVAILABLE, $result->lookup->status);
        $this->assertSame(0, DB::table('address_lookups')->count());
    }

    public function test_an_address_is_asked_once_and_a_miss_again_after_a_week(): void
    {
        $this->fakeDcAddresses();

        $this->check('1700 K St NW');
        $this->check('1700  k st nw');
        $this->check('1 Nowhere St NW');
        $this->check('1 Nowhere St NW');
        Http::assertSentCount(2);

        Carbon::setTestNow(now()->addDays(8));
        $this->check('1700 K St NW');
        $this->check('1 Nowhere St NW');
        Carbon::setTestNow();
        Http::assertSentCount(3);
    }

    public function test_a_checker_lookup_keeps_no_typed_text_but_an_application_lookup_does(): void
    {
        $this->fakeDcAddresses();
        $typed = '1700 K St NW, Suite 400, Washington DC';

        $this->check($typed, AddressLookup::ORIGIN_CHECKER);
        $this->check($typed, AddressLookup::ORIGIN_APPLICATION);

        $checker = (array) DB::table('address_lookups')->where('origin', 'checker')->sole();
        $this->assertNull($checker['query']);
        $this->assertSame(AddressLookup::hash($typed), $checker['query_hash']);
        $this->assertSame('1700 K STREET NW', $checker['matched_address']);
        foreach ($checker as $column => $value) {
            $this->assertStringNotContainsStringIgnoringCase('Suite 400', (string) $value, "{$column} holds the typed text");
        }

        $this->assertSame(AddressLookup::normalize($typed), DB::table('address_lookups')->where('origin', 'application')->value('query'));

        // A checker cache hit still works from the hash alone.
        $this->assertTrue($this->check($typed)->inside);
        Http::assertSentCount(2);
    }
}
