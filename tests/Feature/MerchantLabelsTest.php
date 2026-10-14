<?php

namespace Tests\Feature;

use App\Services\Eligibility\MerchantCandidates;
use App\Services\Eligibility\MerchantLabels;
use Tests\TestCase;

/**
 * data/merchant_labels.csv: the hand-labeled ground truth for Merchant
 * Eligibility Matching. Labels may be blank until the file is locked.
 */
class MerchantLabelsTest extends TestCase
{
    public function test_the_real_label_file_is_valid(): void
    {
        $locked = file_exists(config('eligibility.labels_lock_path'));

        $this->assertSame([], MerchantLabels::problems(MerchantLabels::load(), MerchantCandidates::load(), requireLabels: $locked));
    }

    public function test_the_label_rules(): void
    {
        $candidates = ['MC-001' => ['candidate_id' => 'MC-001', 'name' => 'A', 'address' => '1 K ST NW', 'category' => 'cafe', 'source_url' => 'x']];
        $row = fn (array $overrides) => ['MC-001' => $overrides + [
            'candidate_id' => 'MC-001', 'merchant_name' => 'A', 'address' => '1 K ST NW', 'category' => 'cafe',
            'my_label' => '', 'fail_reason' => '', 'evidence_url' => '', 'my_note' => '', 'split' => 'dev',
        ]];
        $problems = fn (array $overrides, bool $require = false) => array_values(array_filter(
            MerchantLabels::problems($row($overrides), $candidates, $require),
            fn ($p) => ! str_starts_with($p, 'Expected'),
        ));

        $this->assertSame([], $problems([]));
        $this->assertSame(['MC-001: my_label is blank.'], $problems([], require: true));
        $this->assertSame([], $problems(['my_label' => 'undetermined']));
        $this->assertSame([], $problems(['my_label' => 'independent', 'evidence_url' => 'https://example.test/about']));
        $this->assertSame([], $problems(['my_label' => 'not_independent', 'fail_reason' => 'outside_control', 'evidence_url' => 'https://example.test']));
        $this->assertSame(['MC-001: my_label must be independent, not_independent, undetermined.'], $problems(['my_label' => 'maybe']));
        $this->assertSame(['MC-001: not_independent needs a fail_reason: local_chain, franchise, national_chain, outside_control.'],
            $problems(['my_label' => 'not_independent', 'evidence_url' => 'https://example.test']));
        $this->assertSame(['MC-001: fail_reason is only for not_independent.'],
            $problems(['my_label' => 'independent', 'fail_reason' => 'franchise', 'evidence_url' => 'https://example.test']));
        $this->assertSame(['MC-001: independent needs an evidence_url (a public source).'], $problems(['my_label' => 'independent']));
        $this->assertSame(['MC-001: name, address or category differs from the candidates file.'], $problems(['merchant_name' => 'B']));
        $this->assertSame(['MC-001: split must be dev or holdout.'], $problems(['split' => 'test']));
    }

    public function test_split_counts_are_checked(): void
    {
        $this->assertSame(['Expected 40 dev rows, found 0.', 'Expected 20 holdout rows, found 0.'], MerchantLabels::problems([], [], false));
    }
}
