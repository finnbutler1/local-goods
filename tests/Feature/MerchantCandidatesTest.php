<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Services\Eligibility\BidBoundary;
use App\Services\Eligibility\MerchantCandidateBuilder;
use App\Services\Eligibility\MerchantCandidates;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Real merchant candidates (data/merchant_candidates.csv) come from public
 * DC data, sit inside the BID boundary, and are kept apart from the
 * fictional demo: never in seeded data, never on a public page.
 */
class MerchantCandidatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_real_candidates_file_is_well_formed(): void
    {
        $candidates = MerchantCandidates::load();

        $this->assertGreaterThan(60, count($candidates));
        foreach ($candidates as $id => $c) {
            $this->assertMatchesRegularExpression('/^MC-\d{3}$/', $id);
            $this->assertNotSame('', $c['name'], $id);
            $this->assertMatchesRegularExpression('/\bNW\b/i', $c['address'], $id); // the whole BID is in Northwest DC
            $this->assertContains($c['category'], array_keys(config('fraud.category_ticket_ranges')), $id);
            $this->assertStringStartsWith(config('eligibility.licenses_url').'?', $c['source_url'], $id);
        }
    }

    public function test_every_real_candidate_is_inside_the_bid_boundary(): void
    {
        $boundary = BidBoundary::fromFile(config('eligibility.boundary_path'));
        $points = MerchantCandidates::readCsv(config('eligibility.points_path'), ['candidate_id', 'lat', 'lng', 'license_number'], 'candidate_id');

        $this->assertSame(array_keys(MerchantCandidates::load()), array_keys($points), 'Every candidate has exactly one point.');
        foreach ($points as $id => $point) {
            $this->assertTrue($boundary->contains((float) $point['lat'], (float) $point['lng']), "{$id} is outside the boundary");
        }
    }

    public function test_real_candidates_never_appear_in_the_synthetic_demo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $builder = new MerchantCandidateBuilder;
        $candidateKeys = collect(MerchantCandidates::load())->map(fn ($c) => explode('|', $builder->key($c['name'], ''))[0])->all();

        foreach (Merchant::pluck('name') as $name) {
            $this->assertNotContains(explode('|', $builder->key($name, ''))[0], $candidateKeys, "{$name} matches a real candidate");
        }
        $this->assertSame(0, DB::table('redemptions')->whereNotIn('merchant_id', Merchant::pluck('id'))->count());
    }

    public function test_no_real_candidate_name_is_on_a_public_page(): void
    {
        $html = collect(['/', '/eval', '/login'])->map(fn ($url) => $this->get($url)->assertOk()->getContent())->implode("\n");

        foreach (MerchantCandidates::load() as $id => $c) {
            $this->assertDoesNotMatchRegularExpression('/\b'.preg_quote($c['name'], '/').'\b/', $html, "{$id} {$c['name']} is on a public page");
        }
    }

    public function test_the_builder_keeps_only_active_consumer_facing_businesses_inside_the_boundary(): void
    {
        $boundary = new BidBoundary([[[[-77.05, 38.90], [-77.03, 38.90], [-77.03, 38.91], [-77.05, 38.91], [-77.05, 38.90]]]]);
        $in = ['x' => -77.04, 'y' => 38.905];
        $license = fn (array $overrides, array $point = []) => ['attributes' => $overrides + [
            'CUSTOMERNUMBER' => (string) random_int(1, 999999), 'LICENSESTATUS' => 'Active', 'BUSINESSIMPROVEMENTDISTRICT' => 'Golden Triangle BID',
            'CATEGORYSERVICETYPE' => 'Food Services', 'BUSINESSACTIVITY' => 'Restaurant', 'ENTITYTRADENAME' => 'Kept Kitchen',
            'ENTITYNAME' => 'KEPT KITCHEN LLC', 'PREMISEADDRESS' => '1700 K ST NW, WASHINGTON, DC, 20006, USA',
        ], 'geometry' => $point ?: $in];

        $result = (new MerchantCandidateBuilder)->build([
            $license([]),
            $license(['ENTITYTRADENAME' => 'KEPT KITCHEN #2', 'PREMISEADDRESS' => '1700 K ST NW, 100, WASHINGTON, DC, 20006, USA']),
            $license(['ENTITYTRADENAME' => 'Closed Cafe', 'LICENSESTATUS' => 'Closed']),
            $license(['ENTITYTRADENAME' => 'Other BID Cafe', 'BUSINESSIMPROVEMENTDISTRICT' => 'Downtown BID']),
            $license(['ENTITYTRADENAME' => 'Far Away Cafe'], ['x' => -77.10, 'y' => 38.905]),
            $license(['ENTITYTRADENAME' => 'Pat Example', 'ENTITYNAME' => 'PAT EXAMPLE']),
            $license(['ENTITYTRADENAME' => 'Example Law Partners', 'CATEGORYSERVICETYPE' => 'General Sales and Services', 'BUSINESSACTIVITY' => 'General Business']),
            $license(['ENTITYTRADENAME' => 'Corner Books', 'CATEGORYSERVICETYPE' => 'General Sales and Services', 'BUSINESSACTIVITY' => 'General Business', 'PREMISEADDRESS' => '1800 L ST NW, WASHINGTON, DC, 20036, USA']),
            $license(['ENTITYTRADENAME' => 'Morning Coffee', 'PREMISEADDRESS' => '1900 M ST NW, WASHINGTON, DC, 20036, USA']),
        ], $boundary, 'Golden Triangle BID', 'https://example.test/licenses', ['Corner Books|1800 L ST NW, WASHINGTON, DC, 20036' => 'MC-007']);

        $this->assertSame(
            [['MC-007', 'Corner Books', 'retail'], ['MC-008', 'Kept Kitchen', 'restaurant'], ['MC-009', 'Morning Coffee', 'cafe']],
            array_map(fn ($c) => [$c['candidate_id'], $c['name'], $c['category']], $result['candidates']),
        );
        $this->assertStringNotContainsString('Pat Example', json_encode($result));
    }

    public function test_the_command_writes_the_candidates_from_dc_open_data(): void
    {
        $dir = sys_get_temp_dir().'/lg-candidates-'.uniqid();
        mkdir($dir);
        config([
            'eligibility.boundary_path' => "{$dir}/boundary.geojson",
            'eligibility.candidates_path' => "{$dir}/candidates.csv",
            'eligibility.points_path' => "{$dir}/points.csv",
            'eligibility.report_path' => "{$dir}/report.md",
        ]);
        $ring = [[-77.05, 38.90], [-77.03, 38.90], [-77.03, 38.91], [-77.05, 38.91], [-77.05, 38.90]];

        Http::fake([
            config('eligibility.boundary_url').'*' => Http::response(['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]]]]]),
            config('eligibility.licenses_url') => Http::sequence()
                ->push(['objectIds' => [11]])
                ->push(['features' => [['attributes' => [
                    'CUSTOMERNUMBER' => '123', 'LICENSESTATUS' => 'Active', 'BUSINESSIMPROVEMENTDISTRICT' => 'Golden Triangle BID',
                    'CATEGORYSERVICETYPE' => 'Beauty and Grooming Services', 'BUSINESSACTIVITY' => 'Barber Shop', 'ENTITYTRADENAME' => 'Example Barbers',
                    'ENTITYNAME' => 'EXAMPLE BARBERS LLC', 'PREMISEADDRESS' => '1700 K ST NW, WASHINGTON, DC, 20006, USA',
                ], 'geometry' => ['x' => -77.04, 'y' => 38.905]]]]),
        ]);

        $this->artisan('eligibility:build-candidates')->expectsOutputToContain('1 candidates written')->assertSuccessful();

        $this->assertSame(['MC-001'], array_keys(MerchantCandidates::load()));
        $this->assertStringContainsString('Candidates kept** | **1', file_get_contents("{$dir}/report.md"));
        Http::assertSent(fn ($request) => ! str_contains($request->body(), 'OWNER') && ! str_contains($request->body(), 'AGENT'));
    }
}
