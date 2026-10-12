<?php

namespace App\Console\Commands;

use App\Services\Fraud\FraudEvalReport;
use App\Services\Fraud\FraudEvalRunner;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Measures Redemption Fraud Detection against hand-labeled cases. Uses the
 * real production check (same prompt file and model), so it calls the
 * Claude API for any case it has not already scored with this prompt.
 */
class FraudEval extends Command
{
    protected $signature = 'fraud:eval
        {--cases=data/fraud_eval_cases.csv : Hand-labeled cases (CSV)}
        {--out=docs/evals : Where to save the results}
        {--cache= : Cache folder for AI replies (default storage/app/fraud-eval-cache)}';

    protected $description = 'Run the hand-labeled fraud eval cases through Redemption Fraud Detection and report agreement';

    public function handle(FraudEvalRunner $runner, FraudEvalReport $report): int
    {
        try {
            $cases = $runner->loadCases($this->path('cases'));
        } catch (InvalidArgumentException $e) {
            foreach (explode("\n", $e->getMessage()) as $line) {
                $this->error($line);
            }

            return self::FAILURE;
        }

        $this->info('Scoring '.count($cases).' cases with prompt version '.FraudEvalRunner::promptVersion().'...');

        $run = $runner->run(
            $cases,
            $this->option('cache') ?: storage_path('app/fraud-eval-cache'),
            fn (string $line) => $this->line("  {$line}"),
        );

        $this->newLine();
        $this->line("Model: {$run['model']} · prompt {$run['prompt_version']} · new API calls: {$run['api_calls']}");

        if ($run['errors'] !== []) {
            $this->error(count($run['errors']).' case(s) could not be scored: '.implode(', ', $run['errors']).'.');
            $this->error('Nothing was saved. Run the command again: scored cases are cached, so only these are retried.');

            return self::FAILURE;
        }

        foreach (['dev' => 'Dev', 'holdout' => 'Holdout', 'all' => 'All'] as $key => $title) {
            $s = $run['summary'][$key];
            $m = $s['matrix'];
            $this->newLine();
            $this->info("{$title}: {$s['agreement_pct']}% agreement ({$s['agree']}/{$s['cases']})");
            $this->table(['Human ↓ / AI →', 'normal (clear)', 'suspicious (hold)'], [
                ['normal', $m['normal']['normal'], $m['normal']['suspicious']],
                ['suspicious', $m['suspicious']['normal'], $m['suspicious']['suspicious']],
            ]);
        }

        $disagreements = array_values(array_filter($run['results'], fn ($r) => ! $r['agree']));
        $this->newLine();
        $this->info('Disagreements: '.count($disagreements));

        foreach ($disagreements as $r) {
            $this->newLine();
            $this->line("<options=bold>{$r['case_id']}</> ({$r['split']}) \${$r['amount']} at {$r['merchant']}, {$r['local_time']}");
            $this->line("  You:  {$r['my_label']}: {$r['notes']}");
            $this->line("  AI:   {$r['ai_decision']} ({$r['risk_score']}): {$r['explanation']}");
        }

        $paths = $report->save($run, $this->path('out'));
        $this->newLine();
        $this->info('Saved: '.implode(', ', array_map(fn ($p) => str_replace(base_path().'/', '', $p), $paths)));

        return self::SUCCESS;
    }

    /** Option paths are relative to the project folder unless they start with "/". */
    private function path(string $option): string
    {
        $path = $this->option($option);

        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
