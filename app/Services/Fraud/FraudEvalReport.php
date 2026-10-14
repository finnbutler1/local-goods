<?php

namespace App\Services\Fraud;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Saves fraud:eval runs under docs/evals. Every run keeps its own JSON and
 * Markdown file, so results from before and after a prompt change both stay.
 */
class FraudEvalReport
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Writes the run files, the latest results page and the latest JSON.
     *
     * @param  array<string, mixed>  $run
     * @return array<string, string> paths written
     */
    public function save(array $run, string $dir): array
    {
        File::ensureDirectoryExists("{$dir}/runs");

        // Runs on different cases get their own file, even with the same prompt on the same day.
        $name = Carbon::parse($run['run_at'])->format('Y-m-d').'-'.$run['model'].'-prompt-'.$run['prompt_version']
            .(isset($run['cases_version']) ? '-cases-'.$run['cases_version'] : '');
        $runJson = "{$dir}/runs/{$name}.json";
        file_put_contents($runJson, json_encode($run, self::JSON_FLAGS)."\n");

        $history = $this->history($dir);
        $headline = $this->headline($history);
        $latest = $run + ['history' => $history, 'headline' => $headline];

        $paths = [
            'run_json' => $runJson,
            'run_md' => "{$dir}/runs/{$name}.md",
            'latest_md' => "{$dir}/fraud-eval-results.md",
            'latest_json' => "{$dir}/fraud-eval-latest.json",
        ];

        file_put_contents($paths['run_md'], $this->markdown($run, [], null, '../../../'));
        file_put_contents($paths['latest_md'], $this->markdown($run, $history, $headline, '../../'));
        file_put_contents($paths['latest_json'], json_encode($latest, self::JSON_FLAGS)."\n");

        return $paths;
    }

    /**
     * The saved latest run (with history and headline), or null if
     * `php artisan fraud:eval` has not been run yet.
     *
     * @return array<string, mixed>|null
     */
    public function latest(): ?array
    {
        $path = config('fraud.eval_latest_path');

        return is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }

    /**
     * One row per saved run, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $dir): array
    {
        return collect(File::glob("{$dir}/runs/*.json"))
            ->map(fn ($path) => ['path' => $path] + json_decode(file_get_contents($path), true))
            ->sortBy('run_at')
            ->map(fn ($run) => ['summary' => self::summaries($run['results'])] + $run)
            ->map(fn ($run) => [
                'run_at' => $run['run_at'],
                'model' => $run['model'],
                'prompt_version' => $run['prompt_version'],
                'dev_pct' => $run['summary']['dev']['agreement_pct'],
                'holdout_pct' => $run['summary']['holdout']['agreement_pct'],
                'holdout_2_pct' => $run['summary']['holdout_2']['agreement_pct'],
                'holdout_3_pct' => $run['summary']['holdout_3']['agreement_pct'],
                'all_pct' => $run['summary']['all']['agreement_pct'],
                'name' => basename($run['path'], '.json'),
                'file' => 'runs/'.basename($run['path'], '.json').'.md',
                'note' => $run['note'] ?? null,
                'summary' => $run['summary'],
            ])
            ->values()
            ->all();
    }

    /**
     * The headline: catch rate and hold precision on one split of one chosen
     * run (config fraud.eval_headline). Later runs never replace it
     * automatically, because a score only counts as clean if those cases were
     * unseen when the prompt was last changed.
     *
     * @param  list<array<string, mixed>>  $history
     * @return array<string, mixed>|null
     */
    public function headline(array $history): ?array
    {
        $config = config('fraud.eval_headline');
        $row = collect($history)->firstWhere('name', $config['run'] ?? null);
        $split = $row['summary'][$config['split'] ?? ''] ?? null;

        if ($split === null || $split['cases'] === 0) {
            return null;
        }

        $current = $row['prompt_version'] === FraudEvalRunner::promptVersion();
        $c = $split['catch'];
        $p = $split['hold_precision'];

        return [
            'run_at' => $row['run_at'],
            'prompt_version' => $row['prompt_version'],
            'file' => $row['file'],
            'split' => $config['split'],
            'text' => "Caught {$c['hits']} of {$c['of']} suspicious cases; {$p['hits']} of {$p['of']} holds were suspicious.",
            'note' => $config['note'] ?? null,
            'sample' => "Measured on {$split['cases']} unseen cases, {$c['of']} of them labeled suspicious by a person, with "
                .($current ? 'the current prompt' : "prompt {$row['prompt_version']}").'. 95% confidence intervals: catch rate '
                .self::ci($c['ci95']).', hold precision '.self::ci($p['ci95'])."; overall agreement {$split['agreement_pct']}% "
                ."({$split['agree']} of {$split['cases']}, ".self::ci($split['agreement_ci95']).').',
            'summary' => $split,
            'pattern_notes' => $config['pattern_notes'] ?? [],
        ];
    }

    /** "46.2–80.6%" */
    public static function ci(?array $ci): string
    {
        return $ci === null ? 'n/a' : "{$ci[0]}–{$ci[1]}%";
    }

    /** "17 of 26 (65.4%, 95% CI 46.2–80.6%)" */
    public static function rate(array $rate): string
    {
        return $rate['of'] === 0 ? 'n/a (none)' : "{$rate['hits']} of {$rate['of']} ({$rate['pct']}%, 95% CI ".self::ci($rate['ci95']).')';
    }

    /**
     * Per-split summaries rebuilt from a run's results, so runs saved before
     * catch rate and hold precision existed report them too.
     *
     * @param  list<array<string, mixed>>  $results
     * @return array<string, array<string, mixed>>
     */
    private static function summaries(array $results): array
    {
        return collect(FraudEvalRunner::SPLITS)
            ->map(fn ($title, $split) => FraudEvalRunner::summarize(array_filter($results, fn ($r) => $r['split'] === $split)))
            ->put('all', FraudEvalRunner::summarize($results))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  list<array<string, mixed>>  $history
     * @param  array<string, mixed>|null  $headline
     * @param  string  $root  relative path from the Markdown file back to the repo root
     */
    public function markdown(array $run, array $history, ?array $headline = null, string $root = '../../'): string
    {
        $s = $run['summary'];
        $date = Carbon::parse($run['run_at'])->format('F j, Y g:i A T');
        $pct = fn ($split) => $split['agreement_pct'] === null ? 'n/a' : "{$split['agreement_pct']}% ({$split['agree']}/{$split['cases']})";

        $md = "# Redemption Fraud Detection eval results\n\n";

        if ($headline !== null) {
            $md .= "**Headline: {$headline['text']}**\n\n{$headline['sample']}\n\n";
            $md .= 'From the run on '.Carbon::parse($headline['run_at'])->format('F j, Y')
                ." (prompt `{$headline['prompt_version']}`, `{$headline['split']}` cases, [details]({$headline['file']})). "
                .($headline['note'] ?? '')."\n\n";

            if (($lock = FraudEvalLock::load()) !== null) {
                $md .= "**Locked before the AI saw them:** the {$lock['case_count']} `{$lock['split']}` cases were labeled by hand in commit "
                    ."`".substr($lock['labeling_commit'], 0, 7)."` and locked in [`data/fraud_eval_lock.json`]({$root}data/fraud_eval_lock.json) "
                    ."(SHA-256 of every row, combined `".substr($lock['combined_sha256'], 0, 12)."`). `fraud:eval` refuses to run if any locked row changes.\n\n";
            }

            $md .= $this->patternsMarkdown($headline);
        }

        $md .= $headline === null ? '' : "## Latest run\n\n";

        if (! empty($run['note'])) {
            $md .= "> **Note on this run:** {$run['note']}\n\n";
        }
        $md .= "| | |\n|---|---|\n";
        $md .= "| Run | {$date} |\n";
        $md .= "| Model | `{$run['model']}` |\n";
        $md .= "| Prompt version | `{$run['prompt_version']}` (first 8 characters of the SHA-256 of [`redemption-fraud-detection.md`]({$root}resources/prompts/redemption-fraud-detection.md)) |\n";
        $casesVersion = isset($run['cases_version']) ? ", version `{$run['cases_version']}`" : '';
        $md .= "| Cases | {$run['case_count']}{$casesVersion} hand-labeled synthetic redemptions ([`data/fraud_eval_cases.csv`]({$root}data/fraud_eval_cases.csv)) |\n\n";

        $md .= "## Agreement with the human labels\n\n";
        $md .= "| Split | Cases (labeled suspicious) | Agreement | Catch rate: suspicious cases the AI held | Hold precision: holds that were suspicious |\n|---|---|---|---|---|\n";
        $descriptions = [
            'holdout_3' => 'labeled and locked after the last prompt change, never seen while changing it',
            'holdout_2' => 'labeled after the last prompt change, never seen while changing it',
            'holdout' => 'not used to change the prompt',
            'dev' => 'may be used to improve the prompt',
        ];

        foreach ($descriptions as $key => $description) {
            if (($s[$key]['cases'] ?? 0) === 0) {
                continue;
            }

            // Bold only the headline score (or the holdout score when no headline is set).
            $isHeadline = $headline === null
                ? $key === 'holdout'
                : $headline['run_at'] === $run['run_at'] && $headline['split'] === $key;
            $b = $isHeadline ? '**' : '';
            $md .= '| '.$b.FraudEvalRunner::SPLITS[$key].$b." ({$description}) | {$this->counts($s[$key])} | {$pct($s[$key])} | {$b}".self::rate($s[$key]['catch'])."{$b} | {$b}".self::rate($s[$key]['hold_precision'])."{$b} |\n";
        }

        $md .= "| All cases | {$this->counts($s['all'])} | {$pct($s['all'])} | ".self::rate($s['all']['catch']).' | '.self::rate($s['all']['hold_precision'])." |\n\n";
        $md .= "The AI's `hold` counts as *suspicious* and `clear` as *normal*.";

        if ($run['errors'] !== []) {
            $md .= ' **'.count($run['errors']).' case(s) could not be scored and are left out:** '.implode(', ', $run['errors']).'.';
        }

        $md .= "\n\n## Confusion matrices\n\nRows are the human label, columns are the AI's call.\n\n";

        foreach (FraudEvalRunner::SPLITS as $key => $title) {
            if (($s[$key]['cases'] ?? 0) === 0) {
                continue;
            }

            $m = $s[$key]['matrix'];
            $md .= "**{$title}**\n\n| Human ↓ / AI → | normal (clear) | suspicious (hold) |\n|---|---|---|\n";
            $md .= "| normal | {$m['normal']['normal']} | {$m['normal']['suspicious']} (false alarm) |\n";
            $md .= "| suspicious | {$m['suspicious']['normal']} (missed) | {$m['suspicious']['suspicious']} |\n\n";
        }

        $disagreements = array_filter($run['results'], fn ($r) => ! $r['agree']);
        $md .= '## Disagreements ('.count($disagreements).")\n\n";

        if ($disagreements === []) {
            $md .= "None.\n\n";
        } else {
            $md .= "| Case | Split | Redemption | Human | Human notes | AI | AI explanation |\n|---|---|---|---|---|---|---|\n";
            foreach ($disagreements as $r) {
                $md .= "| {$r['case_id']} | {$r['split']} | \${$r['amount']} at {$this->cell($r['merchant'])}, {$r['local_time']} | {$r['my_label']} | {$this->cell($r['notes'])} | {$r['ai_decision']} ({$r['risk_score']}) | {$this->cell($r['explanation'])} |\n";
            }
            $md .= "\n";
        }

        $md .= "## Every case\n\n| Case | Split | Redemption | Human | AI | Score | Agree | Pattern tags |\n|---|---|---|---|---|---|---|---|\n";
        foreach ($run['results'] as $r) {
            $tags = implode(', ', array_map(fn ($t) => FraudEvalPatterns::TAGS[$t], $r['tags'] ?? []));
            $md .= "| {$r['case_id']} | {$r['split']} | \${$r['amount']} at {$this->cell($r['merchant'])}, {$r['local_time']} | {$r['my_label']} | {$r['ai_decision']} | {$r['risk_score']} | ".($r['agree'] ? 'yes' : '**no**')." | {$tags} |\n";
        }

        if ($history !== []) {
            $md .= "\n## Run history\n\nEvery run is kept. A new prompt version means the prompt file changed between runs.\n\n";
            $md .= "Each cell: agreement (agreed/cases) and how many of the suspicious cases the AI caught.\n\n";
            $md .= "| Run | Model | Prompt version | Dev | Holdout | Holdout 2 | Holdout 3 | Details | Note |\n|---|---|---|---|---|---|---|---|---|\n";
            foreach ($history as $h) {
                $md .= '| '.Carbon::parse($h['run_at'])->format('Y-m-d H:i')." | `{$h['model']}` | `{$h['prompt_version']}` | "
                    .implode(' | ', array_map(fn ($split) => self::historyCell($h['summary'][$split]), ['dev', 'holdout', 'holdout_2', 'holdout_3']))
                    ." | [{$h['file']}]({$h['file']}) | {$this->cell($h['note'] ?? '')} |\n";
            }
        }

        $md .= "\nGenerated by `php artisan fraud:eval`. Method: see [TRANSPARENCY.md]({$root}TRANSPARENCY.md#how-we-measure-it).\n";

        return $md;
    }

    /** "90% (18/20), caught 7 of 7" or "n/a" for a split the run did not have. */
    public static function historyCell(array $split): string
    {
        return $split['cases'] === 0
            ? 'n/a'
            : "{$split['agreement_pct']}% ({$split['agree']}/{$split['cases']}), caught {$split['catch']['hits']} of {$split['catch']['of']}";
    }

    private function counts(array $split): string
    {
        return "{$split['cases']} ({$split['catch']['of']})";
    }

    /** Misses and false alarms on the headline split, by pattern tag and merchant category. */
    private function patternsMarkdown(array $headline): string
    {
        $patterns = $headline['summary']['patterns'];
        $notes = $headline['pattern_notes'];
        $md = "## Misses and false alarms by pattern (`{$headline['split']}`)\n\n";
        $md .= "Tags are worked out from each case's facts only, never from the person's label or the AI's answer. A case can have several tags. "
            ."*Missed*: labeled suspicious, AI cleared. *False alarm*: labeled normal, AI held. The \"why\" comes from the AI's own explanations.\n\n";

        foreach (['tags' => ['Pattern', FraudEvalPatterns::TAGS], 'categories' => ['Merchant category', []]] as $key => [$heading, $names]) {
            $md .= "| {$heading} | Cases | Labeled suspicious | Missed | False alarms | Why |\n|---|---|---|---|---|---|\n";
            foreach ($patterns[$key] as $name => $r) {
                $md .= '| '.($names[$name] ?? $name)." | {$r['cases']} | {$r['suspicious']} | {$r['missed']} | {$r['false_alarms']} | {$this->cell($notes[$name] ?? '')} |\n";
            }
            $md .= "\n";
        }

        return $md;
    }

    /** Keeps free text from breaking a Markdown table. */
    private function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], $text);
    }
}
