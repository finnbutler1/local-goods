<?php

namespace App\Console\Commands;

use App\Services\Eligibility\MerchantCandidates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Writes data/sources/merchant_candidate_activity.csv: the license "business
 * activity" (e.g. "Restaurant", "Barber Shop") of every candidate, looked
 * up by the license number saved in the points file. Asks DC's license
 * server for that one field only, never owner or entity names, and leaves
 * the candidates and label files untouched.
 */
class FetchMerchantActivity extends Command
{
    protected $signature = 'eligibility:fetch-activity';

    protected $description = 'Add each merchant candidate\'s license business activity from DC Open Data';

    public function handle(): int
    {
        $points = MerchantCandidates::readCsv(config('eligibility.points_path'), ['candidate_id', 'lat', 'lng', 'license_number'], 'candidate_id');
        $licenses = array_column($points, 'license_number', 'candidate_id');

        $activityByLicense = [];
        foreach (array_chunk(array_values(array_unique($licenses)), 100) as $batch) {
            $quoted = implode(',', array_map(fn ($n) => "'".str_replace("'", "''", $n)."'", $batch));
            $response = Http::timeout(120)->asForm()->post(config('eligibility.licenses_url'), [
                'where' => "CUSTOMERNUMBER IN ({$quoted})",
                'outFields' => 'CUSTOMERNUMBER,BUSINESSACTIVITY',
                'returnGeometry' => 'false',
                'f' => 'json',
            ]);

            if ($response->failed() || $response->json('error') !== null) {
                $this->error('The DC license server returned an error. Nothing was written.');

                return self::FAILURE;
            }

            foreach ($response->json('features') ?? [] as $feature) {
                $a = $feature['attributes'];
                $activityByLicense[(string) $a['CUSTOMERNUMBER']] = trim((string) ($a['BUSINESSACTIVITY'] ?? ''));
            }
        }

        $missing = array_keys(array_diff_key(array_flip($licenses), $activityByLicense));
        if ($missing !== []) {
            $this->error('No license found for '.implode(', ', $missing).'. Nothing was written.');

            return self::FAILURE;
        }

        $handle = fopen(config('eligibility.activity_path'), 'w');
        fputcsv($handle, MerchantCandidates::ACTIVITY_COLUMNS, escape: '');
        foreach ($licenses as $id => $license) {
            fputcsv($handle, [$id, $activityByLicense[$license]], escape: '');
        }
        fclose($handle);

        $this->info('Business activity written for '.count($licenses).' candidates.');

        return self::SUCCESS;
    }
}
