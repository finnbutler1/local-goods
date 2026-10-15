<?php

namespace App\Services\Eligibility;

/**
 * Freezes the hand-labeled merchant ground truth, the same way as the fraud
 * eval (FraudEvalLock): a SHA-256 of every labeled row (facts, label,
 * reason, evidence, note and split) and the commit the labels were made in,
 * so nobody (person or AI) can change a label after seeing AI results.
 * Every labeled row is locked, in both splits.
 */
class MerchantLabelLock
{
    public static function path(): string
    {
        return config('eligibility.labels_lock_path');
    }

    /** SHA-256 of the row's values in column order, so line endings or quoting style don't matter. */
    public static function rowHash(array $row): string
    {
        return hash('sha256', json_encode(array_values($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, array<string, string>>  $rows  from MerchantLabels::load
     * @return array<string, mixed>
     */
    public static function build(array $rows, string $commit, string $lockedAt): array
    {
        $labeled = self::labeled($rows);
        $hashes = self::hashes($labeled);

        return [
            'file' => 'data/merchant_labels.csv',
            'labeling_commit' => $commit,
            'locked_at' => $lockedAt,
            'row_count' => count($hashes),
            'splits' => self::counts($labeled, 'split'),
            'labels' => self::counts($labeled, 'my_label'),
            'fail_reasons' => self::counts(array_filter($labeled, fn ($r) => $r['fail_reason'] !== ''), 'fail_reason'),
            'row_hash' => 'SHA-256 of the JSON array of the row\'s '.count(MerchantLabels::COLUMNS).' values in column order',
            'combined_sha256' => self::combined($hashes),
            'rows' => $hashes,
        ];
    }

    /** The saved lock, or null if the labels have not been locked yet. */
    public static function load(): ?array
    {
        $path = self::path();

        return is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }

    /**
     * Every way the label file differs from the lock: changed, missing or
     * added rows.
     *
     * @param  array<string, array<string, string>>  $rows
     * @return list<string>
     */
    public static function problems(array $rows, array $lock): array
    {
        $now = self::hashes($rows);
        $problems = [];

        foreach ($lock['rows'] as $id => $hash) {
            if (! isset($now[$id])) {
                $problems[] = "{$id}: locked row is missing";
            } elseif (! hash_equals($hash, $now[$id])) {
                $problems[] = "{$id}: changed since it was locked";
            }
        }
        foreach (array_diff_key($now, $lock['rows']) as $id => $hash) {
            $problems[] = "{$id}: not in the lock (the label file is closed to new rows)";
        }

        return $problems;
    }

    /** @return array<string, string> candidate_id => row hash */
    private static function hashes(array $rows): array
    {
        $hashes = [];
        foreach ($rows as $row) {
            $hashes[$row['candidate_id']] = self::rowHash($row);
        }

        return $hashes;
    }

    private static function labeled(array $rows): array
    {
        return array_filter($rows, fn ($r) => $r['my_label'] !== '');
    }

    /** @return array<string, int> */
    private static function counts(array $rows, string $column): array
    {
        $counts = array_count_values(array_column($rows, $column));
        ksort($counts);

        return $counts;
    }

    private static function combined(array $hashes): string
    {
        return hash('sha256', implode("\n", array_map(fn ($id, $h) => "{$id}:{$h}", array_keys($hashes), $hashes)));
    }
}
