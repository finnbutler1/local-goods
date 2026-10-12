<?php

namespace App\Services\Fraud;

use App\Services\Ai\ClaudeClient;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Runs the hand-labeled eval cases through the real Redemption Fraud
 * Detection check and measures how often it agrees with the human labels.
 *
 * "hold" counts as suspicious and "clear" as normal. Replies are cached by
 * model + prompt + signals, so re-running an unchanged eval costs nothing.
 */
class FraudEvalRunner
{
    public const LABELS = ['normal', 'suspicious'];

    public const SPLITS = ['dev', 'holdout'];

    private const COLUMNS = [
        'case_id', 'merchant', 'category', 'amount', 'local_time', 'balance_before', 'recent_history',
        'visits_7d', 'visits_30d', 'past_redemptions', 'median_past_amount', 'my_label', 'notes', 'split',
    ];

    public function __construct(
        private RedemptionFraudDetector $detector,
        private SignalCalculator $signals,
        private ClaudeClient $claude,
    ) {}

    public static function promptPath(): string
    {
        return resource_path('prompts/redemption-fraud-detection.md');
    }

    /** Short fingerprint of the prompt file, so every result names the exact prompt it used. */
    public static function promptVersion(): string
    {
        return substr(hash_file('sha256', self::promptPath()), 0, 8);
    }

    /**
     * Reads and checks the cases file. Throws with every problem listed if
     * anything is missing or unlabeled, so the AI never scores a bad file.
     *
     * @return list<array<string, string>>
     */
    public function loadCases(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Cases file not found: {$path}");
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');

        if ($header !== self::COLUMNS) {
            throw new InvalidArgumentException('The cases file must have exactly these columns: '.implode(', ', self::COLUMNS));
        }

        $cases = [];
        $problems = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if ($row === [null]) {
                continue; // blank line
            }

            $case = array_combine($header, $row);
            $id = $case['case_id'];

            if (isset($cases[$id])) {
                $problems[] = "{$id}: duplicate case_id";
            }
            if (! in_array($case['my_label'], self::LABELS, true)) {
                $problems[] = "{$id}: my_label must be normal or suspicious";
            }
            if (! in_array($case['split'], self::SPLITS, true)) {
                $problems[] = "{$id}: split must be dev or holdout";
            }
            if (config("fraud.category_ticket_ranges.{$case['category']}") === null) {
                $problems[] = "{$id}: unknown category {$case['category']}";
            }
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $case['local_time'])) {
                $problems[] = "{$id}: local_time must look like 14:05";
            }
            foreach (['amount', 'balance_before'] as $money) {
                if (! is_numeric($case[$money])) {
                    $problems[] = "{$id}: {$money} must be a dollar amount";
                }
            }
            foreach (['visits_7d', 'visits_30d', 'past_redemptions'] as $count) {
                if (! ctype_digit($case[$count])) {
                    $problems[] = "{$id}: {$count} must be a whole number";
                }
            }
            if ((int) $case['visits_7d'] > (int) $case['visits_30d'] || (int) $case['visits_30d'] > (int) $case['past_redemptions']) {
                $problems[] = "{$id}: visits_7d <= visits_30d <= past_redemptions must hold";
            }
            if (($case['median_past_amount'] === '') !== ((int) $case['past_redemptions'] === 0)) {
                $problems[] = "{$id}: median_past_amount is blank only when there are no past redemptions";
            }

            $cases[$id] = $case;
        }

        fclose($handle);

        if ($problems !== []) {
            throw new InvalidArgumentException("The cases file has problems:\n- ".implode("\n- ", $problems));
        }

        return array_values($cases);
    }

    /**
     * The same anonymous signals production would send for this redemption.
     *
     * @param  array<string, string>  $case
     * @return array<string, mixed>
     */
    public function signalsFor(array $case): array
    {
        return $this->signals->fromFacts(
            amountCents: self::cents($case['amount']),
            balanceCents: self::cents($case['balance_before']),
            pastRedemptions: (int) $case['past_redemptions'],
            medianCents: $case['median_past_amount'] === '' ? null : self::cents($case['median_past_amount']),
            visitsLast7Days: (int) $case['visits_7d'],
            visitsLast30Days: (int) $case['visits_30d'],
            category: $case['category'],
            localHour: (int) substr($case['local_time'], 0, 2),
        );
    }

    /**
     * Scores every case and returns the full run record.
     *
     * @param  list<array<string, string>>  $cases
     * @param  callable(string): void|null  $progress
     * @return array<string, mixed>
     */
    public function run(array $cases, string $cacheDir, ?callable $progress = null): array
    {
        $prompt = file_get_contents(self::promptPath());
        $model = $this->claude->model();
        File::ensureDirectoryExists($cacheDir);

        $results = [];
        $errors = [];
        $apiCalls = 0;

        foreach ($cases as $case) {
            $signals = $this->signalsFor($case);
            $cacheFile = $cacheDir.'/'.hash('sha256', $model."\n".$prompt."\n".json_encode($signals)).'.json';
            $cached = is_file($cacheFile);

            if ($cached) {
                $verdict = json_decode(file_get_contents($cacheFile), true);
            } else {
                $apiCalls++;
                $v = $this->detector->score($signals);

                if ($v->scoredBy === 'fallback') {
                    // A failed call is not an answer: report it, don't cache it, don't count it.
                    $errors[] = $case['case_id'];
                    $progress && $progress("{$case['case_id']}: AI check failed, will retry on the next run");

                    continue;
                }

                $verdict = ['decision' => $v->decision, 'risk_score' => $v->riskScore, 'explanation' => $v->explanation];
                file_put_contents($cacheFile, json_encode($verdict, JSON_PRETTY_PRINT));
            }

            $aiLabel = $verdict['decision'] === FraudVerdict::HOLD ? 'suspicious' : 'normal';
            $results[] = [
                'case_id' => $case['case_id'],
                'split' => $case['split'],
                'merchant' => $case['merchant'],
                'category' => $case['category'],
                'amount' => $case['amount'],
                'local_time' => $case['local_time'],
                'my_label' => $case['my_label'],
                'notes' => $case['notes'],
                'ai_decision' => $verdict['decision'],
                'ai_label' => $aiLabel,
                'risk_score' => $verdict['risk_score'],
                'explanation' => $verdict['explanation'],
                'agree' => $aiLabel === $case['my_label'],
                'cached' => $cached,
            ];

            $progress && $progress("{$case['case_id']}: {$verdict['decision']} ({$verdict['risk_score']})".($cached ? ' [cached]' : ''));
        }

        return [
            'run_at' => now(config('fraud.timezone'))->toIso8601String(),
            'model' => $model,
            'prompt_version' => self::promptVersion(),
            'case_count' => count($cases),
            'api_calls' => $apiCalls,
            'errors' => $errors,
            'summary' => [
                'dev' => self::summarize(array_filter($results, fn ($r) => $r['split'] === 'dev')),
                'holdout' => self::summarize(array_filter($results, fn ($r) => $r['split'] === 'holdout')),
                'all' => self::summarize($results),
            ],
            'results' => $results,
        ];
    }

    /**
     * Agreement and a confusion matrix: matrix[my_label][ai_label] = count.
     *
     * @param  array<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public static function summarize(array $results): array
    {
        $matrix = array_fill_keys(self::LABELS, array_fill_keys(self::LABELS, 0));

        foreach ($results as $r) {
            $matrix[$r['my_label']][$r['ai_label']]++;
        }

        $total = count($results);
        $agree = $matrix['normal']['normal'] + $matrix['suspicious']['suspicious'];

        return [
            'cases' => $total,
            'agree' => $agree,
            'agreement_pct' => $total ? round(100 * $agree / $total, 1) : null,
            'matrix' => $matrix,
        ];
    }

    private static function cents(string $dollars): int
    {
        return (int) round(((float) $dollars) * 100);
    }
}
