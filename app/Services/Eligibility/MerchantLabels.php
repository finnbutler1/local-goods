<?php

namespace App\Services\Eligibility;

/**
 * The hand-labeled ground truth for Merchant Eligibility Matching
 * (data/merchant_labels.csv), checked against the independence rule's
 * three outcomes. Labels may be blank until the file is locked
 * (data/merchant_labels_lock.json); after that every row must be labeled.
 */
class MerchantLabels
{
    public const COLUMNS = ['candidate_id', 'merchant_name', 'address', 'category', 'my_label', 'fail_reason', 'evidence_url', 'my_note', 'split'];

    public const LABELS = ['independent', 'not_independent', 'undetermined'];

    /** Which part of the rule a not_independent business fails. outside_control is part (1). */
    public const FAIL_REASONS = ['local_chain', 'franchise', 'national_chain', 'outside_control'];

    public const SPLITS = ['dev' => 40, 'holdout' => 20];

    /**
     * @return array<string, array<string, string>> keyed by candidate_id
     */
    public static function load(?string $path = null): array
    {
        return MerchantCandidates::readCsv($path ?? config('eligibility.labels_path'), self::COLUMNS, 'candidate_id');
    }

    /**
     * Everything wrong with the label file, as plain sentences.
     *
     * @param  array<string, array<string, string>>  $rows
     * @param  array<string, array<string, string>>  $candidates
     * @return list<string>
     */
    public static function problems(array $rows, array $candidates, bool $requireLabels): array
    {
        $problems = [];
        $splits = array_count_values(array_column($rows, 'split'));

        foreach (self::SPLITS as $split => $expected) {
            if (($splits[$split] ?? 0) !== $expected) {
                $problems[] = "Expected {$expected} {$split} rows, found ".($splits[$split] ?? 0).'.';
            }
        }

        foreach ($rows as $id => $row) {
            $candidate = $candidates[$id] ?? null;
            $label = $row['my_label'];
            $reason = $row['fail_reason'];

            if ($candidate === null) {
                $problems[] = "{$id}: not in the candidates file.";
            } elseif ([$row['merchant_name'], $row['address'], $row['category']] !== [$candidate['name'], $candidate['address'], $candidate['category']]) {
                $problems[] = "{$id}: name, address or category differs from the candidates file.";
            }
            if (! array_key_exists($row['split'], self::SPLITS)) {
                $problems[] = "{$id}: split must be dev or holdout.";
            }

            if ($label === '') {
                if ($requireLabels) {
                    $problems[] = "{$id}: my_label is blank.";
                }
                if ($reason !== '' || $row['evidence_url'] !== '') {
                    $problems[] = "{$id}: fail_reason or evidence_url is filled in but my_label is blank.";
                }

                continue;
            }

            if (! in_array($label, self::LABELS, true)) {
                $problems[] = "{$id}: my_label must be ".implode(', ', self::LABELS).'.';
            }
            if ($label === 'not_independent' && ! in_array($reason, self::FAIL_REASONS, true)) {
                $problems[] = "{$id}: not_independent needs a fail_reason: ".implode(', ', self::FAIL_REASONS).'.';
            }
            if ($label !== 'not_independent' && $reason !== '') {
                $problems[] = "{$id}: fail_reason is only for not_independent.";
            }
            if (in_array($label, ['independent', 'not_independent'], true) && ! preg_match('#^https?://\S+$#', $row['evidence_url'])) {
                $problems[] = "{$id}: {$label} needs an evidence_url (a public source).";
            }
        }

        return $problems;
    }
}
