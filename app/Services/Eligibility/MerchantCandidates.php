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
    /**
     * @return array<string, array<string, string>> keyed by candidate_id
     */
    public static function load(?string $path = null): array
    {
        return self::readCsv($path ?? config('eligibility.candidates_path'), MerchantCandidateBuilder::COLUMNS, 'candidate_id');
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
