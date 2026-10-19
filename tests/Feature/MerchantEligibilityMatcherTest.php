<?php

namespace Tests\Feature;

use App\Services\Ai\ClaudeClient;
use App\Services\Eligibility\EligibilityAssessment;
use App\Services\Eligibility\MerchantCandidates;
use App\Services\Eligibility\MerchantEligibilityMatcher;
use App\Services\Eligibility\MerchantLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

class MerchantEligibilityMatcherTest extends TestCase
{
    private const CANDIDATE = [
        'candidate_id' => 'MC-900', 'name' => 'Example Corner Cafe', 'address' => '1700 K ST NW, WASHINGTON, DC, 20006',
        'category' => 'cafe', 'source_url' => 'https://example.test/license', 'business_activity' => 'Restaurant',
    ];

    private function matcherReturning(FakeClaudeClient $fake): MerchantEligibilityMatcher
    {
        $this->app->instance(ClaudeClient::class, $fake);

        return $this->app->make(MerchantEligibilityMatcher::class);
    }

    private function reply(array $overrides = []): FakeClaudeClient
    {
        return new FakeClaudeClient(json_encode($overrides + [
            'eligible' => false, 'classification' => 'franchise', 'confidence' => 0.92,
            'reasoning' => 'This is a franchise location of a national sandwich brand.', 'needs_human_review' => false,
        ]));
    }

    public function test_a_valid_reply_is_used(): void
    {
        $a = $this->matcherReturning($this->reply())->assess(self::CANDIDATE);

        $this->assertFalse($a->eligible);
        $this->assertSame('franchise', $a->classification);
        $this->assertSame(0.92, $a->confidence);
        $this->assertFalse($a->needsHumanReview);
        $this->assertSame('claude-opus-5-5', $a->assessedBy);
    }

    public function test_an_independent_reply_is_eligible(): void
    {
        $a = $this->matcherReturning($this->reply(['eligible' => true, 'classification' => 'independent', 'confidence' => 1]))->assess(self::CANDIDATE);

        $this->assertTrue($a->eligible);
        $this->assertSame(1.0, $a->confidence);
    }

    public function test_low_confidence_or_undetermined_always_needs_review(): void
    {
        $this->assertTrue($this->matcherReturning($this->reply(['confidence' => 0.6]))->assess(self::CANDIDATE)->needsHumanReview);
        $this->assertTrue($this->matcherReturning($this->reply(['classification' => 'undetermined', 'confidence' => 0.95]))->assess(self::CANDIDATE)->needsHumanReview);
    }

    public function test_the_review_threshold_is_exactly_seventy_percent(): void
    {
        $this->assertSame(0.7, config('eligibility.review_if_confidence_below'));

        $this->assertFalse($this->matcherReturning($this->reply(['confidence' => 0.7]))->assess(self::CANDIDATE)->needsHumanReview);
        $this->assertTrue($this->matcherReturning($this->reply(['confidence' => 0.69]))->assess(self::CANDIDATE)->needsHumanReview);
    }

    public function test_the_ais_own_request_for_review_is_kept(): void
    {
        $a = $this->matcherReturning($this->reply(['confidence' => 0.95, 'needs_human_review' => true]))->assess(self::CANDIDATE);

        $this->assertTrue($a->needsHumanReview);
        $this->assertFalse($a->isFallback());
    }

    public function test_an_assessment_survives_the_saved_file_round_trip(): void
    {
        $a = $this->matcherReturning($this->reply(['reasoning' => "  Owned locally.\n"]))->assess(self::CANDIDATE);

        $this->assertSame('Owned locally.', $a->reasoning);
        $this->assertEquals($a, EligibilityAssessment::fromArray(json_decode(json_encode($a->toArray()), true)));
    }

    /** @return array<string, array{0: string|RuntimeException}> */
    public static function badReplies(): array
    {
        $valid = ['eligible' => false, 'classification' => 'franchise', 'confidence' => 0.9, 'reasoning' => 'A franchise.', 'needs_human_review' => false];

        return [
            'api error' => [new RuntimeException('Connection timed out')],
            'refusal' => [new RuntimeException('The model declined to answer.')],
            'not json' => ['Sure! It looks independent to me.'],
            'eligible but not independent' => [json_encode(['eligible' => true] + $valid)],
            'independent but not eligible' => [json_encode(['classification' => 'independent'] + $valid)],
            'unknown classification' => [json_encode(['classification' => 'probably_fine'] + $valid)],
            'confidence above 1' => [json_encode(['confidence' => 1.5] + $valid)],
            'confidence below 0' => [json_encode(['confidence' => -0.1] + $valid)],
            'confidence as text' => [json_encode(['confidence' => 'high'] + $valid)],
            'blank reasoning' => [json_encode(['reasoning' => '  '] + $valid)],
            'missing field' => [json_encode(array_diff_key($valid, ['needs_human_review' => 1]))],
            'eligible as text' => [json_encode(['eligible' => 'false'] + $valid)],
            'review flag as a number' => [json_encode(['needs_human_review' => 0] + $valid)],
            'reasoning not text' => [json_encode(['reasoning' => ['A franchise.']] + $valid)],
            'a list, not an object' => ['[]'],
            'empty reply' => [''],
        ];
    }

    #[DataProvider('badReplies')]
    public function test_a_failure_or_invalid_reply_goes_to_a_person_and_never_guesses(string|RuntimeException $reply): void
    {
        $a = $this->matcherReturning(new FakeClaudeClient($reply))->assess(self::CANDIDATE);

        $this->assertTrue($a->isFallback());
        $this->assertFalse($a->eligible);
        $this->assertSame('undetermined', $a->classification);
        $this->assertSame(0.0, $a->confidence);
        $this->assertTrue($a->needsHumanReview);
        $this->assertSame(MerchantEligibilityMatcher::FALLBACK_REASONING, $a->reasoning);
    }

    public function test_the_ai_sees_only_the_four_public_facts(): void
    {
        $fake = $this->reply();
        $this->matcherReturning($fake)->assess(self::CANDIDATE + ['my_label' => 'independent', 'my_note' => 'secret note']);

        $this->assertSame(
            ['name' => 'Example Corner Cafe', 'address' => '1700 K ST NW, WASHINGTON, DC, 20006', 'category' => 'cafe', 'business_activity' => 'Restaurant'],
            json_decode($fake->calls[0]['user'], true),
        );
    }

    public function test_no_hand_label_reaches_the_ai_for_any_labeled_candidate(): void
    {
        $candidates = MerchantCandidates::load();
        $activity = MerchantCandidates::activity();

        foreach (MerchantLabels::load() as $id => $label) {
            $input = MerchantEligibilityMatcher::input($candidates[$id] + ['business_activity' => $activity[$id] ?? '']);
            foreach (['my_note', 'evidence_url'] as $field) {
                if ($label[$field] !== '') {
                    $this->assertStringNotContainsString($label[$field], $input, "{$id} {$field} would reach the AI");
                }
            }
            $this->assertDoesNotMatchRegularExpression('/my_label|fail_reason|not_independent|holdout|"dev"/', $input, $id);
        }
    }

    public function test_the_prompt_has_the_rule_verbatim_and_no_labeled_merchant(): void
    {
        $flatten = fn (string $text) => trim((string) preg_replace('/\s+/', ' ', str_replace('>', ' ', $text)));
        preg_match('/## Independence rule.*?\n\n(>.*?)\n\n/s', file_get_contents(base_path('CLAUDE.md')), $m);
        $prompt = file_get_contents(MerchantEligibilityMatcher::promptPath());

        $this->assertNotEmpty($m[1] ?? null, 'Could not find the rule in CLAUDE.md');
        $this->assertStringContainsString($flatten($m[1]), $flatten($prompt));

        foreach (MerchantLabels::load() as $id => $label) {
            $this->assertStringNotContainsStringIgnoringCase($label['merchant_name'], $prompt, "{$id} is named in the prompt");
            // Short notes ("many locations") are ordinary phrases; a copied note would be longer.
            if (strlen($label['my_note']) >= 30) {
                $this->assertStringNotContainsString($label['my_note'], $prompt, "{$id} note is in the prompt");
            }
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', MerchantEligibilityMatcher::promptVersion());
    }

    public function test_the_prompt_is_frozen(): void
    {
        $this->assertSame(config('eligibility.frozen_prompt_version'), MerchantEligibilityMatcher::promptVersion(),
            'The merchant eligibility prompt changed after it was frozen. A change needs Finn\'s say-so and a fresh unseen holdout.');
    }
}
