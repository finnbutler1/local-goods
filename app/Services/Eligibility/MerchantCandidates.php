<?php

namespace App\Services\Eligibility;

use InvalidArgumentException;

/**
 * Reads data/merchant_candidates.csv: real businesses inside the BID that
 * Merchant Eligibility Matching may assess. None is a Local Goods
 * participant. Any page that shows one must say so with
 * config('eligibility.candidate_label').
 */
class MerchantCandidates
{
    public const ACTIVITY_COLUMNS = ['candidate_id', 'business_activity'];

    /** The map point only: no license number, which could lead to a sole trader's legal name. */
    public const POINT_COLUMNS = ['candidate_id', 'lat', 'lng'];

    /**
     * @return array<string, array<string, string>> keyed by candidate_id
     */
    public static function load(?string $path = null): array
    {
        return self::readCsv($path ?? config('eligibility.candidates_path'), MerchantCandidateBuilder::COLUMNS, 'candidate_id');
    }

    /**
     * The license business activity for each candidate, or an empty array
     * before eligibility:fetch-activity has run.
     *
     * @return array<string, string> candidate_id => business activity
     */
    public static function activity(?string $path = null): array
    {
        $path ??= config('eligibility.activity_path');

        return is_file($path) ? array_column(self::readCsv($path, self::ACTIVITY_COLUMNS, 'candidate_id'), 'business_activity', 'candidate_id') : [];
    }

    /** The DC license number in a candidate's source link, or null if it has none. */
    public static function licenseNumber(string $sourceUrl): ?string
    {
        parse_str((string) parse_url($sourceUrl, PHP_URL_QUERY), $query);

        return preg_match("/^CUSTOMERNUMBER='([^']+)'$/", (string) ($query['where'] ?? ''), $m) ? $m[1] : null;
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, array<string, string>>
     */
    public static function readCsv(string $path, array $columns, string $key): array
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException("Cannot read {$path}.");
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');

        if ($header !== $columns) {
            fclose($handle);
            throw new InvalidArgumentException(basename($path).' must have exactly these columns: '.implode(',', $columns));
        }

        $rows = [];
        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if ($values === [null]) {
                continue;
            }
            $row = array_combine($columns, array_pad($values, count($columns), ''));
            $rows[$row[$key]] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
