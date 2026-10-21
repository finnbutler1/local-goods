<?php

namespace App\Console\Commands;

use App\Services\Eligibility\BidBoundary;
use App\Services\Eligibility\MerchantCandidateBuilder;
use App\Services\Eligibility\MerchantCandidates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Builds data/merchant_candidates.csv: real, consumer-facing businesses
 * inside the Golden Triangle BID, from public DC data. Downloads the BID
 * boundary, asks DC's license server for active licenses inside it, then
 * double-checks each one (see MerchantCandidateBuilder). Re-running keeps
 * the ids of businesses already listed. Candidates are never Local Goods
 * participants and never appear in the synthetic demo.
 */
class BuildMerchantCandidates extends Command
{
    protected $signature = 'eligibility:build-candidates
        {--keep-boundary : Use the saved boundary file instead of downloading it again}';

    protected $description = 'Build the list of real merchant candidates inside the BID from DC Open Data';

    private const LICENSE_FIELDS = 'OBJECTID,CUSTOMERNUMBER,LICENSESTATUS,CATEGORYSERVICETYPE,BUSINESSACTIVITY,ENTITYTRADENAME,ENTITYNAME,PREMISEADDRESS,BUSINESSIMPROVEMENTDISTRICT';

    public function handle(): int
    {
        $builder = new MerchantCandidateBuilder(config('eligibility.category_overrides'));

        try {
            $boundary = $this->boundary();
            $licenses = $this->licenses($boundary);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $existing = file_exists(config('eligibility.candidates_path'))
            ? collect(MerchantCandidates::load())->mapWithKeys(fn ($c) => [$c['name'].'|'.$c['address'] => $c['candidate_id']])->all()
            : [];

        $result = $builder->build($licenses, $boundary, config('eligibility.boundary_name'), config('eligibility.licenses_url'), $existing);

        $this->writeCsv(config('eligibility.candidates_path'), MerchantCandidateBuilder::COLUMNS, $result['candidates']);
        $this->writeCsv(config('eligibility.points_path'), MerchantCandidates::POINT_COLUMNS, $result['points']);
        $this->writeCsv(config('eligibility.activity_path'), MerchantCandidates::ACTIVITY_COLUMNS, $result['activity']);
        file_put_contents(config('eligibility.report_path'), $this->report($builder->report, $result['candidates']));

        foreach ($builder->report as $line => $count) {
            $this->line(str_pad(str_replace('_', ' ', $line), 30).$count);
        }
        $this->info(count($result['candidates']).' candidates written to '.config('eligibility.candidates_path'));

        return self::SUCCESS;
    }

    private function boundary(): BidBoundary
    {
        $path = config('eligibility.boundary_path');

        if (! $this->option('keep-boundary')) {
            $response = Http::timeout(60)->get(config('eligibility.boundary_url'), [
                'where' => "NAME='".config('eligibility.boundary_name')."'",
                'outFields' => 'NAME,WEB_URL,EDITED',
                'outSR' => 4326,
                'f' => 'geojson',
            ]);

            if ($response->failed() || count($response->json('features') ?? []) !== 1) {
                throw new RuntimeException('Could not download exactly one boundary named '.config('eligibility.boundary_name').'.');
            }

            file_put_contents($path, json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }

        return BidBoundary::fromFile($path);
    }

    /**
     * Active licenses inside the boundary, with their map point. Asks for
     * the matching record ids first, then fetches them in batches: the
     * server can silently cut a long result short, so the total must match.
     * Owner and agent names are never requested.
     *
     * @return list<array<string, mixed>>
     */
    private function licenses(BidBoundary $boundary): array
    {
        $ids = $this->query([
            'where' => "LICENSESTATUS='Active'",
            'geometry' => json_encode(['rings' => $boundary->rings(), 'spatialReference' => ['wkid' => 4326]]),
            'geometryType' => 'esriGeometryPolygon',
            'inSR' => 4326,
            'spatialRel' => 'esriSpatialRelIntersects',
            'returnIdsOnly' => 'true',
        ])['objectIds'] ?? [];
        sort($ids);

        $licenses = [];
        foreach (array_chunk($ids, 250) as $batch) {
            array_push($licenses, ...($this->query([
                'objectIds' => implode(',', $batch),
                'outFields' => self::LICENSE_FIELDS,
                'returnGeometry' => 'true',
                'outSR' => 4326,
            ])['features'] ?? []));
        }

        if (count($licenses) !== count($ids)) {
            throw new RuntimeException('Expected '.count($ids).' licenses but received '.count($licenses).'. Nothing was written.');
        }

        return $licenses;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function query(array $params): array
    {
        $response = Http::timeout(120)->asForm()->post(config('eligibility.licenses_url'), $params + ['f' => 'json']);

        if ($response->failed() || $response->json('error') !== null) {
            throw new RuntimeException('The DC license server returned an error: '.json_encode($response->json('error') ?? $response->status()));
        }

        return $response->json();
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, string>>  $rows
     */
    private function writeCsv(string $path, array $columns, array $rows): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, $columns, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($c) => $row[$c], $columns), escape: '');
        }
        fclose($handle);
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, string>>  $candidates
     */
    private function report(array $counts, array $candidates): string
    {
        $lines = ["# Merchant candidates: build report\n",
            'Built '.now(config('fraud.timezone'))->toDateString().' by `php artisan eligibility:build-candidates`.',
            'Boundary: DC GIS Business Improvement Districts layer, "'.config('eligibility.boundary_name').'" (`data/sources/golden_triangle_bid.geojson`).',
            'Licenses: DC Open Data, Basic Business License Points, active licenses returned by a spatial query against that boundary.',
            "\nA license is kept only if DC's spatial query, the license's own BID field and our point-in-polygon check all put it inside the boundary.\n",
            '| Step | Licenses |', '|---|---|'];
        foreach ($counts as $step => $count) {
            $lines[] = '| '.ucfirst(str_replace('_', ' ', $step)).' | '.$count.' |';
        }
        $lines[] = '| **Candidates kept** | **'.count($candidates).'** |';
        $lines[] = "\nBy category: ".collect($candidates)->countBy('category')->sortKeys()->map(fn ($n, $c) => "{$c} {$n}")->implode(', ').'.';

        return implode("\n", $lines)."\n";
    }
}
