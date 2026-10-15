<?php

namespace Tests\Feature;

use App\Services\Eligibility\MerchantCandidates;
use App\Services\Eligibility\MerchantLabelLock;
use App\Services\Eligibility\MerchantLabels;
use Tests\TestCase;

/**
 * The hand-labeled merchant ground truth is locked (data/merchant_labels_lock.json).
 * CI fails if any labeled row changes, disappears or is added.
 */
class MerchantLabelLockTest extends TestCase
{
    public function test_the_real_labels_still_match_their_lock(): void
    {
        $lock = json_decode(file_get_contents(base_path('data/merchant_labels_lock.json')), true);
        $rows = MerchantLabels::load();

        $this->assertSame([], MerchantLabelLock::problems($rows, $lock), 'A locked merchant label was changed after labeling.');
        $this->assertSame(60, $lock['row_count']);
        $this->assertSame(['dev' => 40, 'holdout' => 20], $lock['splits']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $lock['labeling_commit']);
        $this->assertSame(
            MerchantLabelLock::build($rows, $lock['labeling_commit'], $lock['locked_at'])['combined_sha256'],
            $lock['combined_sha256'],
        );
    }

    public function test_once_locked_every_row_is_labeled_and_valid(): void
    {
        $this->assertSame([], MerchantLabels::problems(MerchantLabels::load(), MerchantCandidates::load(), requireLabels: true));
    }

    public function test_changed_missing_and_added_rows_are_reported(): void
    {
        $row = fn (string $id, string $label = 'independent') => [
            'candidate_id' => $id, 'merchant_name' => "Name {$id}", 'address' => '1 K ST NW', 'category' => 'cafe',
            'my_label' => $label, 'fail_reason' => '', 'evidence_url' => 'https://example.test', 'my_note' => 'x', 'split' => 'dev',
        ];
        $lock = MerchantLabelLock::build(['MC-001' => $row('MC-001'), 'MC-002' => $row('MC-002')], str_repeat('a', 40), '2026-10-15T12:00:00-04:00');

        $this->assertSame([], MerchantLabelLock::problems(['MC-001' => $row('MC-001'), 'MC-002' => $row('MC-002')], $lock));
        $this->assertSame(
            ['MC-001: changed since it was locked', 'MC-002: locked row is missing', 'MC-003: not in the lock (the label file is closed to new rows)'],
            MerchantLabelLock::problems(['MC-001' => $row('MC-001', 'undetermined'), 'MC-003' => $row('MC-003')], $lock),
        );
    }

    public function test_a_note_edit_alone_breaks_the_lock(): void
    {
        $rows = MerchantLabels::load();
        $lock = MerchantLabelLock::build($rows, str_repeat('b', 40), '2026-10-15T12:00:00-04:00');
        $first = array_key_first($rows);
        $rows[$first]['my_note'] .= ' (edited)';

        $this->assertSame(["{$first}: changed since it was locked"], MerchantLabelLock::problems($rows, $lock));
    }

    public function test_an_existing_lock_is_never_replaced_without_force(): void
    {
        $path = sys_get_temp_dir().'/merchant-lock-'.uniqid().'.json';
        file_put_contents($path, '{"rows":{}}');
        config(['eligibility.labels_lock_path' => $path]);

        $this->artisan('merchant:lock')->expectsOutputToContain('already locked')->assertFailed();
        $this->assertSame('{"rows":{}}', file_get_contents($path));
        unlink($path);
    }
}
