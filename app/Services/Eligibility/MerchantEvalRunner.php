<?php

namespace App\Services\Eligibility;

use App\Services\Ai\ClaudeClient;
use App\Support\WilsonInterval;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Runs the hand-labeled merchant candidates through the real Merchant
 * Eligibility Matching check and compares its answers with Finn's labels.
 *
 * The AI gets only the public facts (MerchantEligibilityMatcher::input);
 * labels, notes and evidence are joined back in afterwards, for scoring.
 * Replies are cached by model + prompt + input, so re-running an unchanged
 * eval costs nothing. Dev and holdout are scored separately, never pooled.
 */
class MerchantEvalRunner
{
    public const SPLITS = ['dev' => 'Dev', 'holdout' => 'Holdout'];

    /** What the AI's answer means for the eligible yes/no question. */
    public const OUTCOMES = ['eligible', 'not_eligible', 'undetermined'];

    /** Rough size of one reply, for the cost estimate before a run. */
    private const OUTPUT_TOKENS_PER_CALL = 300;

    public function __construct(private MerchantEligibilityMatcher $matcher, private ClaudeClient $claude) {}

    /**
     * The locked labels joined with each candidate's public facts. Throws if
     * the labels are not locked or no longer match the lock, so the AI is
     * never measured against changed labels.
     *
     * @return list<array<string, string>>
     */
    public function loadCases(): array
    {
        $lock = MerchantLabelLock::load();
        if ($lock === null) {
            throw new InvalidArgumentException('The merchant labels are not locked (data/merchant_labels_lock.json is missing). Nothing was scored.');
        }

        $labels = MerchantLabels::load();
        if (($problems = MerchantLabelLock::problems($labels, $lock)) !== []) {
            throw new InvalidArgumentException("The labels no longer match the lock (labeling commit {$lock['labeling_commit']}):\n- "
                .implode("\n- ", $problems)."\nNothing was scored. Restore data/merchant_labels.csv from that commit.");
        }

        $candidates = MerchantCandidates::load();
        if (($problems = MerchantLabels::problems($labels, $candidates, requireLabels: true)) !== []) {
            throw new InvalidArgumentException("The label file has problems:\n- ".implode("\n- ", $problems));
        }

        $activity = MerchantCandidates::activity();
        $cases = [];

        foreach ($labels as $id => $label) {
            $cases[] = $candidates[$id] + ['business_activity' => $activity[$id] ?? ''] + $label;
        }

        return $cases;
    }

    /**
     * How many new (uncached) API calls a run would make, and a rough token
     * count, without calling anything.
     *
     * @param  list<array<string, string>>  $cases  one split
     * @return array{cases: int, calls: int, input_tokens: int, output_tokens: int}
     */
    public function estimate(array $cases, string $cacheDir): array
    {
        $prompt = file_get_contents(MerchantEligibilityMatcher::promptPath());
        $calls = 0;
        $inputChars = 0;

        foreach ($cases as $case) {
            if (! is_file($this->cacheFile($cacheDir, $prompt, $case))) {
                $calls++;
                $inputChars += strlen($prompt) + strlen(MerchantEligibilityMatcher::input($case));
            }
        }

        // About four characters per token for English text, plus the schema and message overhead.
        return [
            'cases' => count($cases),
            'calls' => $calls,
            'input_tokens' => (int) ceil($inputChars / 4) + $calls * 400,
            'output_tokens' => $calls * self::OUTPUT_TOKENS_PER_CALL,
        ];
    }

    /**
     * Scores one split and returns the full run record.
     *
     * @param  list<array<string, string>>  $cases  one split
     * @param  callable(string): void|null  $progress
     * @return array<string, mixed>
     */
    public function run(array $cases, string $split, string $cacheDir, ?callable $progress = null): array
    {
        $prompt = file_get_contents(MerchantEligibilityMatcher::promptPath());
        File::ensureDirectoryExists($cacheDir);

        $results = [];
        $errors = [];
        $apiCalls = 0;

        foreach ($cases as $case) {
            $cacheFile = $this->cacheFile($cacheDir, $prompt, $case);
            $cached = is_file($cacheFile);

            if ($cached) {
                $assessment = EligibilityAssessment::fromArray(json_decode(file_get_contents($cacheFile), true));
            } else {
                $apiCalls++;
                $assessment = $this->matcher->assess($case);

                if ($assessment->isFallback()) {
                    // A failed call is not an answer: report it, don't cache it, don't score it.
                    $errors[] = $case['candidate_id'];
                    $progress && $progress("{$case['candidate_id']}: AI check failed, will retry on the next run");

                    continue;
                }

                file_put_contents($cacheFile, json_encode($assessment->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            $results[] = self::score($case, $assessment) + ['cached' => $cached];
            $progress && $progress("{$case['candidate_id']}: {$assessment->classification} ({$assessment->confidence})".($cached ? ' [cached]' : ''));
        }

        return [
            'run_at' => now(config('fraud.timezone'))->toIso8601String(),
            'model' => $this->claude->model(),
            'prompt_version' => MerchantEligibilityMatcher::promptVersion(),
            'split' => $split,
            'case_count' => count($cases),
            'api_calls' => $apiCalls,
            'errors' => $errors,
            'summary' => self::summarize($results),
            'results' => $results,
        ];
    }

    /**
     * One case's result: the facts, Finn's label and the AI's answer.
     *
     * @param  array<string, string>  $case
     * @return array<string, mixed>
     */
    public static function score(array $case, EligibilityAssessment $a): array
    {
        $outcome = match (true) {
            $a->classification === 'undetermined' => 'undetermined',
            $a->eligible => 'eligible',
            default => 'not_eligible',
        };
        $expected = match ($case['my_label']) {
            'not_independent' => $case['fail_reason'],
            default => $case['my_label'],
        };

        return [
            'candidate_id' => $case['candidate_id'],
            'split' => $case['split'],
            'name' => $case['name'],
            'address' => $case['address'],
            'category' => $case['category'],
            'business_activity' => $case['business_activity'],
            'my_label' => $case['my_label'],
            'fail_reason' => $case['fail_reason'],
            'evidence_url' => $case['evidence_url'],
            'my_note' => $case['my_note'],
            'ai_classification' => $a->classification,
            'ai_eligible' => $a->eligible,
            'ai_outcome' => $outcome,
            'confidence' => $a->confidence,
            'reasoning' => $a->reasoning,
            'needs_human_review' => $a->needsHumanReview,
            'model' => $a->assessedBy,
            // Eligible yes/no: the AI's "eligible" against "Finn labeled it independent".
            'agree' => $a->eligible === ($case['my_label'] === 'independent'),
            // The exact classification: independent, undetermined or the fail_reason.
            'class_match' => $a->classification === $expected,
        ];
    }

    /**
     * Everything the report shows for one split.
     *
     * @param  array<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public static function summarize(array $results): array
    {
        $results = array_values($results);
        $total = count($results);
        $count = fn (callable $where) => count(array_filter($results, $where));
        $rate = fn (int $hits, int $of) => [
            'hits' => $hits,
            'of' => $of,
            'pct' => $of ? round(100 * $hits / $of, 1) : null,
            'ci95' => WilsonInterval::percent($hits, $of),
        ];

        $matrix = array_fill_keys(MerchantLabels::LABELS, array_fill_keys(self::OUTCOMES, 0));
        foreach ($results as $r) {
            $matrix[$r['my_label']][$r['ai_outcome']]++;
        }

        $independent = $count(fn ($r) => $r['my_label'] === 'independent');
        $aiEligible = $count(fn ($r) => $r['ai_eligible']);
        $bothEligible = $count(fn ($r) => $r['ai_eligible'] && $r['my_label'] === 'independent');

        $byReason = [];
        foreach (MerchantLabels::FAIL_REASONS as $reason) {
            $rows = array_filter($results, fn ($r) => $r['fail_reason'] === $reason);
            $n = count($rows);
            $byReason[$reason] = [
                'cases' => $n,
                'not_eligible' => $rate(count(array_filter($rows, fn ($r) => $r['ai_outcome'] === 'not_eligible')), $n),
                'same_reason' => $rate(count(array_filter($rows, fn ($r) => $r['class_match'])), $n),
                'undetermined' => count(array_filter($rows, fn ($r) => $r['ai_outcome'] === 'undetermined')),
                'called_eligible' => count(array_filter($rows, fn ($r) => $r['ai_eligible'])),
            ];
        }

        $chains = array_filter($results, fn ($r) => $r['my_label'] === 'not_independent');

        $humanUndetermined = array_filter($results, fn ($r) => $r['my_label'] === 'undetermined');
        $decided = $total - count($humanUndetermined);

        return [
            'cases' => $total,
            // Eligible yes/no agreement, next to the score of always answering "not eligible".
            'agreement' => $rate($count(fn ($r) => $r['agree']), $total),
            'baseline_always_not_eligible' => $rate($total - $independent, $total),
            // Of Finn's independent cases, how many the AI called eligible.
            'recall' => $rate($bothEligible, $independent),
            // Of the AI's eligible calls, how many Finn labeled independent.
            'precision' => $rate($bothEligible, $aiEligible),
            'class_match' => $rate($count(fn ($r) => $r['class_match']), $total),
            'matrix' => $matrix,
            'by_fail_reason' => $byReason,
            'undetermined' => [
                // Of Finn's undetermined cases, how often the AI also said undetermined instead of guessing.
                'human_undetermined' => count($humanUndetermined),
                'ai_also_undetermined' => $rate(count(array_filter($humanUndetermined, fn ($r) => $r['ai_outcome'] === 'undetermined')), count($humanUndetermined)),
                // Of the cases Finn could decide, how often the AI said undetermined.
                'ai_undetermined_on_decided' => $rate($count(fn ($r) => $r['my_label'] !== 'undetermined' && $r['ai_outcome'] === 'undetermined'), $decided),
            ],
            // Triage: how the AI handles the businesses Finn labeled not independent, and how much goes to a person.
            'triage' => [
                'chains_not_eligible' => $rate(count(array_filter($chains, fn ($r) => $r['ai_outcome'] === 'not_eligible')), count($chains)),
                'chains_called_eligible' => $rate(count(array_filter($chains, fn ($r) => $r['ai_eligible'])), count($chains)),
                'routed_to_review' => $rate($count(fn ($r) => $r['needs_human_review']), $total),
            ],
            // Both answers mean "not eligible", but the reason differs.
            'category_disagreements' => count(array_filter($chains, fn ($r) => $r['ai_outcome'] === 'not_eligible' && ! $r['class_match'])),
            'needs_human_review' => $count(fn ($r) => $r['needs_human_review']),
            'disagreements' => $count(fn ($r) => ! $r['class_match']),
        ];
    }

    /** @param  array<string, string>  $case */
    private function cacheFile(string $cacheDir, string $prompt, array $case): string
    {
        return $cacheDir.'/'.hash('sha256', $this->claude->model()."\n".$prompt."\n".MerchantEligibilityMatcher::input($case)).'.json';
    }
}
