<?php

namespace App\Services\Eligibility;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Saves merchant:eval runs. Every run keeps its own JSON and Markdown file
 * under docs/evals/merchant-runs, so the history shows which prompt version
 * saw the holdout. Also writes the latest results (both splits) and the
 * AI-only assessments the BID review queue reads.
 *
 * The committed files name real businesses (the repo is source data). The
 * public web page never does: see MerchantEvalController.
 */
class MerchantEvalReport
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public const LABEL_NAMES = ['independent' => 'Independent', 'not_independent' => 'Not independent', 'undetermined' => 'Undetermined'];

    public const OUTCOME_NAMES = ['eligible' => 'Eligible', 'not_eligible' => 'Not eligible', 'undetermined' => 'Undetermined'];

    /** Shown with the category disagreements. Finn's wording of the caveat; the labels stay locked. */
    public const CATEGORY_NOTE = 'In these cases both answers mean "not eligible"; only the reason differs. Finn notes that some of Finn\'s own fail_reason labels may be inaccurate (for example, company-operated locations labeled franchise). The labels stay locked and unchanged, so these still count as classification disagreements.';

    public const CLASS_NAMES = [
        'independent' => 'Independent', 'local_chain' => 'Local chain', 'franchise' => 'Franchise',
        'national_chain' => 'National chain', 'outside_control' => 'Outside control', 'undetermined' => 'Undetermined',
    ];

    /** The saved runs, oldest first. @return list<array<string, mixed>> */
    public function runs(string $dir): array
    {
        return collect(File::glob("{$dir}/merchant-runs/*.json"))
            ->map(fn ($path) => ['name' => basename($path, '.json')] + json_decode(file_get_contents($path), true))
            // Rebuilt from the results, so runs saved before a measure existed report it too.
            ->map(fn ($run) => ['summary' => MerchantEvalRunner::summarize($run['results'])] + $run)
            ->sortBy('run_at')
            ->values()
            ->all();
    }

    /** Prompt versions that have scored the holdout, in order. @return list<string> */
    public function holdoutSeenBy(string $dir): array
    {
        return collect($this->runs($dir))->where('split', 'holdout')->pluck('prompt_version')->unique()->values()->all();
    }

    /**
     * Writes the run files, the latest results (JSON and Markdown) and the
     * assessments file.
     *
     * @param  array<string, mixed>  $run
     * @return array<string, string> paths written
     */
    public function save(array $run, string $dir, string $assessmentsPath): array
    {
        File::ensureDirectoryExists("{$dir}/merchant-runs");

        $name = Carbon::parse($run['run_at'])->format('Y-m-d').'-'.$run['model'].'-prompt-'.$run['prompt_version'].'-'.$run['split'];
        $paths = [
            'run_json' => "{$dir}/merchant-runs/{$name}.json",
            'run_md' => "{$dir}/merchant-runs/{$name}.md",
            'latest_md' => "{$dir}/merchant-eval-results.md",
            'latest_json' => "{$dir}/merchant-eval-latest.json",
            'assessments' => $assessmentsPath,
        ];

        file_put_contents($paths['run_json'], json_encode($run, self::JSON_FLAGS)."\n");

        $runs = $this->runs($dir);
        $latest = [
            'updated_at' => $run['run_at'],
            'current_prompt_version' => MerchantEligibilityMatcher::promptVersion(),
            'splits' => collect(MerchantEvalRunner::SPLITS)->map(fn ($title, $split) => collect($runs)->where('split', $split)->last())->all(),
            'holdout_seen_by' => $this->holdoutSeenBy($dir),
            'history' => array_map(fn ($r) => [
                'name' => $r['name'],
                'run_at' => $r['run_at'],
                'model' => $r['model'],
                'prompt_version' => $r['prompt_version'],
                'split' => $r['split'],
                'note' => $r['note'] ?? null,
                'cases' => $r['summary']['cases'],
                'agreement_pct' => $r['summary']['agreement']['pct'],
                'recall' => $r['summary']['recall'],
                'precision' => $r['summary']['precision'],
                'file' => "merchant-runs/{$r['name']}.md",
            ], $runs),
        ];

        file_put_contents($paths['run_md'], $this->runMarkdown($run, '../../../'));
        file_put_contents($paths['latest_md'], $this->latestMarkdown($latest));
        file_put_contents($paths['latest_json'], json_encode($latest, self::JSON_FLAGS)."\n");
        $this->saveAssessments($run, $assessmentsPath);

        return $paths;
    }

    /**
     * The saved latest results, or null before the first merchant:eval.
     *
     * @return array<string, mixed>|null
     */
    public function latest(): ?array
    {
        $path = config('eligibility.eval_latest_path');

        return is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }

    /**
     * The AI's answers only (no labels, notes or evidence), keyed by
     * candidate, for the BID review queue.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function assessments(): array
    {
        $path = config('eligibility.assessments_path');

        return is_file($path) ? json_decode(file_get_contents($path), true)['assessments'] ?? [] : [];
    }

    /** "46.2–80.6%" */
    public static function ci(?array $ci): string
    {
        return $ci === null ? 'n/a' : "{$ci[0]}–{$ci[1]}%";
    }

    /** "5 of 7 (71.4%, 95% CI 35.9–91.8%)" */
    public static function rate(array $rate): string
    {
        return $rate['of'] === 0 ? 'n/a (none)' : "{$rate['hits']} of {$rate['of']} ({$rate['pct']}%, 95% CI ".self::ci($rate['ci95']).')';
    }

    /** @param  array<string, mixed>  $run */
    private function saveAssessments(array $run, string $path): void
    {
        $saved = is_file($path) ? json_decode(file_get_contents($path), true)['assessments'] ?? [] : [];

        foreach ($run['results'] as $r) {
            $saved[$r['candidate_id']] = [
                'prompt_version' => $run['prompt_version'],
                'assessed_at' => $run['run_at'],
                'eligible' => $r['ai_eligible'],
                'classification' => $r['ai_classification'],
                'confidence' => $r['confidence'],
                'reasoning' => $r['reasoning'],
                'needs_human_review' => $r['needs_human_review'],
                'assessed_by' => $r['model'],
            ];
        }
        ksort($saved);

        file_put_contents($path, json_encode([
            'about' => 'Merchant Eligibility Matching answers for real candidates, written by php artisan merchant:eval. AI output only: no hand labels. Every business here is a candidate, not a Local Goods participant.',
            'assessments' => $saved,
        ], self::JSON_FLAGS)."\n");
    }

    /** @param  array<string, mixed>  $latest */
    private function latestMarkdown(array $latest): string
    {
        $lines = [
            '# Merchant Eligibility Matching: eval results',
            '',
            'Written by `php artisan merchant:eval`. Finn hand-labeled 60 real businesses in the Golden Triangle BID against the independence rule (40 dev, 20 holdout), with a public source for each, and locked the labels (`data/merchant_labels_lock.json`) before the AI saw them. The AI saw only each business\'s name, address, category and license business activity.',
            '',
            '> **Every business named here is a candidate, not a Local Goods participant.** These files name real businesses because the repo holds the source data; the public web page shows only totals.',
            '',
            '> **The AI had no web access.** '.MerchantEligibilityMatcher::KNOWLEDGE_NOTICE,
            '',
            '**Dev and holdout are reported separately and never pooled.** The prompt may be changed using dev cases only; the holdout is scored once per prompt version.',
            '',
            'Prompt versions that have seen the holdout: '.($latest['holdout_seen_by'] === [] ? 'none yet.' : '`'.implode('`, `', $latest['holdout_seen_by']).'`.'),
            '',
        ];

        foreach (MerchantEvalRunner::SPLITS as $split => $title) {
            $run = $latest['splits'][$split] ?? null;
            $lines[] = "## {$title}";
            $lines[] = '';
            $lines[] = $run === null ? 'Not run yet.' : $this->splitMarkdown($run, '../../');
            $lines[] = '';
        }

        $lines[] = '## Run history';
        $lines[] = '';
        $lines[] = '| Run | Split | Prompt | Cases | Agreement | Independent found | Eligible calls right | Note |';
        $lines[] = '|---|---|---|---|---|---|---|---|';
        foreach ($latest['history'] as $h) {
            $lines[] = "| [{$h['run_at']}]({$h['file']}) | {$h['split']} | `{$h['prompt_version']}` | {$h['cases']} | {$h['agreement_pct']}% | "
                .self::short($h['recall']).' | '.self::short($h['precision']).' | '.str_replace('|', '/', (string) $h['note']).' |';
        }

        return implode("\n", $lines)."\n";
    }

    /** @param  array<string, mixed>  $run */
    private function runMarkdown(array $run, string $root): string
    {
        $title = MerchantEvalRunner::SPLITS[$run['split']] ?? $run['split'];

        return implode("\n", [
            "# Merchant eval run: {$title}, prompt `{$run['prompt_version']}`",
            '',
            '> Every business named here is a candidate, not a Local Goods participant. '.MerchantEligibilityMatcher::KNOWLEDGE_NOTICE,
            '',
            $this->splitMarkdown($run, $root),
        ])."\n";
    }

    /** @param  array<string, mixed>  $run */
    private function splitMarkdown(array $run, string $root): string
    {
        $s = $run['summary'];
        $lines = [
            "Run {$run['run_at']} · model `{$run['model']}` · prompt [`{$run['prompt_version']}`]({$root}resources/prompts/merchant-eligibility.md) · {$s['cases']} cases".(($run['note'] ?? null) ? " · {$run['note']}" : ''),
            '',
            '| Measure | Result |',
            '|---|---|',
            '| Eligible yes/no agreement | '.self::rate($s['agreement']).' |',
            '| Baseline: always answer "not eligible" | '.self::rate($s['baseline_always_not_eligible']).' |',
            '| Of Finn\'s independent businesses, AI called eligible | '.self::rate($s['recall']).' |',
            '| Of the AI\'s eligible calls, Finn labeled independent | '.self::rate($s['precision']).' |',
            '| Exact classification match | '.self::rate($s['class_match']).' |',
            '| Triage: chains and franchises correctly called not eligible | '.self::rate($s['triage']['chains_not_eligible']).' |',
            '| Triage: chains and franchises wrongly called eligible | '.self::rate($s['triage']['chains_called_eligible']).' |',
            '| Triage: share of all cases routed to human review | '.self::rate($s['triage']['routed_to_review']).' |',
            '| AI said undetermined on a case Finn decided | '.self::rate($s['undetermined']['ai_undetermined_on_decided']).' |',
            '| Finn undetermined: AI also said undetermined | '.($s['undetermined']['human_undetermined'] === 0 ? 'n/a: no case is labeled undetermined' : self::rate($s['undetermined']['ai_also_undetermined'])).' |',
            '',
            'Confusion matrix (rows: Finn, columns: AI):',
            '',
            '| Finn ↓ / AI → | '.implode(' | ', self::OUTCOME_NAMES).' |',
            '|---|---|---|---|',
        ];
        foreach ($s['matrix'] as $label => $row) {
            $lines[] = '| '.self::LABEL_NAMES[$label].' | '.implode(' | ', $row).' |';
        }

        $lines[] = '';
        $lines[] = 'By the reason Finn gave (not independent cases):';
        $lines[] = '';
        $lines[] = '| Reason | Cases | AI said not eligible | AI gave the same reason | AI undetermined | AI called eligible |';
        $lines[] = '|---|---|---|---|---|---|';
        foreach ($s['by_fail_reason'] as $reason => $r) {
            $lines[] = '| '.self::CLASS_NAMES[$reason]." | {$r['cases']} | ".self::rate($r['not_eligible']).' | '.self::rate($r['same_reason'])." | {$r['undetermined']} | {$r['called_eligible']} |";
        }

        $category = array_filter($run['results'], fn ($r) => self::isCategoryDisagreement($r));
        $lines[] = '';
        $lines[] = 'Category disagreements ('.count($category).'): '.self::CATEGORY_NOTE;
        $lines[] = '';
        $lines[] = count($category) === 0 ? 'None.' : implode(', ', array_map(
            fn ($r) => "{$r['candidate_id']} {$r['name']} (Finn: ".self::CLASS_NAMES[$r['fail_reason']].', AI: '.self::CLASS_NAMES[$r['ai_classification']].')',
            $category,
        )).'.';

        $disagreements = array_filter($run['results'], fn ($r) => ! $r['class_match']);
        $lines[] = '';
        $lines[] = 'Disagreements ('.count($disagreements).'): every case where the AI\'s classification differs from Finn\'s, including the category disagreements above. **Bold** means the eligible yes/no answer differs too.';
        foreach ($disagreements as $r) {
            $finn = self::LABEL_NAMES[$r['my_label']].($r['fail_reason'] ? ' ('.self::CLASS_NAMES[$r['fail_reason']].')' : '');
            $head = "{$r['candidate_id']} {$r['name']}";
            $lines[] = '';
            $lines[] = '- '.($r['agree'] ? $head : "**{$head}**")." ({$r['category']}, {$r['business_activity']}). Candidate, not a participant.";
            $lines[] = "  - Finn: {$finn}. ".str_replace("\n", ' ', $r['my_note']).($r['evidence_url'] ? " [Source]({$r['evidence_url']})" : '');
            $lines[] = '  - AI: '.self::CLASS_NAMES[$r['ai_classification']].', confidence '.round(100 * $r['confidence']).'%. '.str_replace("\n", ' ', $r['reasoning']);
        }

        return implode("\n", $lines);
    }

    /** Both answers mean "not eligible", but the classification differs. @param  array<string, mixed>  $r */
    public static function isCategoryDisagreement(array $r): bool
    {
        return $r['my_label'] === 'not_independent' && $r['ai_outcome'] === 'not_eligible' && ! $r['class_match'];
    }

    private static function short(array $rate): string
    {
        return $rate['of'] === 0 ? 'n/a' : "{$rate['hits']} of {$rate['of']}";
    }
}
