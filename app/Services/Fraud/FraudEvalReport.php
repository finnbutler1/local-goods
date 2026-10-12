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

        $name = Carbon::parse($run['run_at'])->format('Y-m-d').'-'.$run['model'].'-prompt-'.$run['prompt_version'];
        $runJson = "{$dir}/runs/{$name}.json";
        file_put_contents($runJson, json_encode($run, self::JSON_FLAGS)."\n");

        $history = $this->history($dir);
        $latest = $run + ['history' => $history];

        $paths = [
            'run_json' => $runJson,
            'run_md' => "{$dir}/runs/{$name}.md",
            'latest_md' => "{$dir}/fraud-eval-results.md",
            'latest_json' => "{$dir}/fraud-eval-latest.json",
        ];

        file_put_contents($paths['run_md'], $this->markdown($run, [], '../../../'));
        file_put_contents($paths['latest_md'], $this->markdown($run, $history, '../../'));
        file_put_contents($paths['latest_json'], json_encode($latest, self::JSON_FLAGS)."\n");

        return $paths;
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
                'all_pct' => $run['summary']['all']['agreement_pct'],
                'file' => 'runs/'.basename($run['path'], '.json').'.md',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  list<array<string, mixed>>  $history
     * @param  string  $root  relative path from the Markdown file back to the repo root
     */
    public function markdown(array $run, array $history, string $root = '../../'): string
    {
        $s = $run['summary'];
        $date = Carbon::parse($run['run_at'])->format('F j, Y g:i A T');
        $pct = fn ($split) => $split['agreement_pct'] === null ? 'n/a' : "{$split['agreement_pct']}% ({$split['agree']}/{$split['cases']})";

        $md = "# Redemption Fraud Detection eval results\n\n";
        $md .= "| | |\n|---|---|\n";
        $md .= "| Run | {$date} |\n";
        $md .= "| Model | `{$run['model']}` |\n";
        $md .= "| Prompt version | `{$run['prompt_version']}` (first 8 characters of the SHA-256 of [`redemption-fraud-detection.md`]({$root}resources/prompts/redemption-fraud-detection.md)) |\n";
        $md .= "| Cases | {$run['case_count']} hand-labeled synthetic redemptions ([`data/fraud_eval_cases.csv`]({$root}data/fraud_eval_cases.csv)) |\n\n";

        $md .= "## Agreement with the human labels\n\n";
        $md .= "| Split | Agreement |\n|---|---|\n";
        $md .= "| **Holdout** (never used to change the prompt) | **{$pct($s['holdout'])}** |\n";
        $md .= "| Dev (may be used to improve the prompt) | {$pct($s['dev'])} |\n";
        $md .= "| All cases | {$pct($s['all'])} |\n\n";
        $md .= "The AI's `hold` counts as *suspicious* and `clear` as *normal*.";

        if ($run['errors'] !== []) {
            $md .= ' **'.count($run['errors']).' case(s) could not be scored and are left out:** '.implode(', ', $run['errors']).'.';
        }

        $md .= "\n\n## Confusion matrices\n\nRows are the human label, columns are the AI's call.\n\n";

        foreach (['dev' => 'Dev', 'holdout' => 'Holdout'] as $key => $title) {
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
            $md .= "| Run | Model | Prompt version | Dev | Holdout | All | Details |\n|---|---|---|---|---|---|---|\n";
            foreach ($history as $h) {
                $md .= '| '.Carbon::parse($h['run_at'])->format('Y-m-d H:i')." | `{$h['model']}` | `{$h['prompt_version']}` | {$h['dev_pct']}% | {$h['holdout_pct']}% | {$h['all_pct']}% | [{$h['file']}]({$h['file']}) |\n";
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
