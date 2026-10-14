<?php

namespace App\Services\Fraud;

/**
 * Freezes a split of hand-labeled eval cases. The lock file stores a SHA-256
 * of every row (facts, label and notes) and the commit the labels were made
 * in, so nobody (person or AI) can change a case after seeing the results.
 * fraud:eval refuses to run if a locked row no longer matches.
 */
class FraudEvalLock
{
    public const SPLIT = 'holdout_3';

    public static function path(): string
    {
        return config('fraud.eval_lock_path');
    }

    /** SHA-256 of the row's values in column order, so line endings or quoting style don't matter. */
    public static function rowHash(array $case): string
    {
        return hash('sha256', json_encode(array_values($case), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  list<array<string, string>>  $cases  every case from FraudEvalRunner::loadCases
     * @return array<string, mixed>
     */
    public static function build(array $cases, string $commit, string $lockedAt): array
    {
        $rows = self::rows($cases);
        $labels = array_count_values(array_column(self::locked($cases), 'my_label'));

        return [
            'split' => self::SPLIT,
            'labeling_commit' => $commit,
            'locked_at' => $lockedAt,
            'case_count' => count($rows),
            'labels' => ['normal' => $labels['normal'] ?? 0, 'suspicious' => $labels['suspicious'] ?? 0],
            'row_hash' => 'SHA-256 of the JSON array of the row\'s 14 values in column order',
            'combined_sha256' => self::combined($rows),
            'rows' => $rows,
        ];
    }

    /** The saved lock, or null if the split has not been locked yet. */
    public static function load(): ?array
    {
        $path = self::path();

        return is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }

    /**
     * Every way the cases differ from the lock: changed, missing or added rows.
     *
     * @param  list<array<string, string>>  $cases
     * @return list<string>
     */
    public static function problems(array $cases, array $lock): array
    {
        $now = self::rows($cases);
        $problems = [];

        foreach ($lock['rows'] as $id => $hash) {
            if (! isset($now[$id])) {
                $problems[] = "{$id}: locked case is missing";
            } elseif (! hash_equals($hash, $now[$id])) {
                $problems[] = "{$id}: changed since it was locked";
            }
        }
        foreach (array_diff_key($now, $lock['rows']) as $id => $hash) {
            $problems[] = "{$id}: not in the lock (".self::SPLIT.' is closed to new cases)';
        }

        return $problems;
    }

    /** @return array<string, string> case_id => row hash */
    private static function rows(array $cases): array
    {
        $rows = [];
        foreach (self::locked($cases) as $case) {
            $rows[$case['case_id']] = self::rowHash($case);
        }

        return $rows;
    }

    private static function locked(array $cases): array
    {
        return array_values(array_filter($cases, fn ($c) => $c['split'] === self::SPLIT));
    }

    private static function combined(array $rows): string
    {
        return hash('sha256', implode("\n", array_map(fn ($id, $h) => "{$id}:{$h}", array_keys($rows), $rows)));
    }
}
