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
            ->map(fn ($run) => [
                'run_at' => $run['run_at'],
                'model' => $run['model'],
                'prompt_version' => $run['prompt_version'],
                'dev_pct' => $run['summary']['dev']['agreement_pct'],
                'holdout_pct' => $run['summary']['holdout']['agreement_pct'],
                'holdout_2_pct' => $run['summary']['holdout_2']['agreement_pct'] ?? null,
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
     * The headline number: one split of one chosen run (config
     * fraud.eval_headline). Later runs never replace it automatically,
     * because a score only counts as clean if those cases were unseen when
     * the prompt was last changed.
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
        $missed = $split['matrix']['suspicious']['normal'];

        return [
            'run_at' => $row['run_at'],
            'prompt_version' => $row['prompt_version'],
            'file' => $row['file'],
            'split' => $config['split'],
            'text' => "{$split['agreement_pct']}% agreement on {$split['cases']} unseen cases with "
                .($current ? 'the current prompt' : "prompt {$row['prompt_version']}")
                .", and {$missed} suspicious ".($missed === 1 ? 'case' : 'cases').' missed.',
            'note' => $config['note'] ?? null,
            'sample' => "Small sample: with {$split['cases']} cases, one case moves the score by "
                .round(100 / $split['cases'], 1).' points.',
        ];
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
            $md .= "**Headline: {$headline['text']}**\n\n";
            $md .= 'From the run on '.Carbon::parse($headline['run_at'])->format('F j, Y')
                ." (prompt `{$headline['prompt_version']}`, `{$headline['split']}` cases, [details]({$headline['file']})). "
                .($headline['note'] ? "{$headline['note']} " : '')."{$headline['sample']}\n\n";
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
        $md .= "| Split | Agreement |\n|---|---|\n";
        $descriptions = [
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
            $md .= '| '.$b.FraudEvalRunner::SPLITS[$key].$b." ({$description}) | {$b}{$pct($s[$key])}{$b} |\n";
        }

        $md .= "| All cases | {$pct($s['all'])} |\n\n";
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

        $md .= "## Every case\n\n| Case | Split | Redemption | Human | AI | Score | Agree |\n|---|---|---|---|---|---|---|\n";
        foreach ($run['results'] as $r) {
            $md .= "| {$r['case_id']} | {$r['split']} | \${$r['amount']} at {$this->cell($r['merchant'])}, {$r['local_time']} | {$r['my_label']} | {$r['ai_decision']} | {$r['risk_score']} | ".($r['agree'] ? 'yes' : '**no**')." |\n";
        }

        if ($history !== []) {
            $md .= "\n## Run history\n\nEvery run is kept. A new prompt version means the prompt file changed between runs.\n\n";
            $md .= "| Run | Model | Prompt version | Dev | Holdout | Holdout 2 | All | Details | Note |\n|---|---|---|---|---|---|---|---|---|\n";
            foreach ($history as $h) {
                $md .= '| '.Carbon::parse($h['run_at'])->format('Y-m-d H:i')." | `{$h['model']}` | `{$h['prompt_version']}` | {$h['dev_pct']}% | {$h['holdout_pct']}% | ".($h['holdout_2_pct'] === null ? 'n/a' : "{$h['holdout_2_pct']}%")." | {$h['all_pct']}% | [{$h['file']}]({$h['file']}) | {$this->cell($h['note'] ?? '')} |\n";
            }
        }

        $md .= "\nGenerated by `php artisan fraud:eval`. Method: see [TRANSPARENCY.md]({$root}TRANSPARENCY.md#how-we-measure-it).\n";

        return $md;
    }

    /** Keeps free text from breaking a Markdown table. */
    private function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], $text);
    }
}
