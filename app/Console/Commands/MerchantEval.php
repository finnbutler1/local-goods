<?php

namespace App\Console\Commands;

use App\Services\Eligibility\MerchantEligibilityMatcher;
use App\Services\Eligibility\MerchantEvalReport;
use App\Services\Eligibility\MerchantEvalRunner;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Measures Merchant Eligibility Matching against Finn's locked hand labels.
 * Uses the real check (same prompt file and model), so it calls the Claude
 * API for every case not already answered with this prompt. One split per
 * run: dev while working on the prompt, holdout once per prompt version.
 */
class MerchantEval extends Command
{
    protected $signature = 'merchant:eval
        {--split=dev : dev or holdout}
        {--dry-run : Only say how many API calls a run would make}
        {--note= : A sentence saved with this run, e.g. what changed since the last one}';

    protected $description = 'Run the hand-labeled merchant candidates through Merchant Eligibility Matching and report agreement';

    public function handle(MerchantEvalRunner $runner, MerchantEvalReport $report): int
    {
        $split = $this->option('split');
        if (! array_key_exists($split, MerchantEvalRunner::SPLITS)) {
            $this->error('--split must be dev or holdout.');

            return self::FAILURE;
        }

        try {
            $cases = array_values(array_filter($runner->loadCases(), fn ($c) => $c['split'] === $split));
        } catch (InvalidArgumentException $e) {
            foreach (explode("\n", $e->getMessage()) as $line) {
                $this->error($line);
            }

            return self::FAILURE;
        }

        $version = MerchantEligibilityMatcher::promptVersion();
        $dir = config('eligibility.eval_dir');

        if ($split === 'holdout' && in_array($version, $report->holdoutSeenBy($dir), true)) {
            $this->error("Prompt {$version} has already scored the holdout. The holdout runs once per prompt version; its results are in docs/evals/merchant-eval-results.md.");

            return self::FAILURE;
        }

        $cache = config('eligibility.eval_cache_path');
        $estimate = $runner->estimate($cases, $cache);
        $this->info("{$estimate['cases']} {$split} cases, prompt {$version}: {$estimate['calls']} new API call(s), about "
            .number_format($estimate['input_tokens']).' input and '.number_format($estimate['output_tokens']).' output tokens.');

        if ($this->option('dry-run')) {
            $this->line('Dry run: nothing was called or saved.');

            return self::SUCCESS;
        }

        $run = $runner->run($cases, $split, $cache, fn (string $line) => $this->line("  {$line}"));
        $run['note'] = $this->option('note');

        $this->newLine();
        $this->line("Model: {$run['model']} · prompt {$run['prompt_version']} · new API calls: {$run['api_calls']}");

        if ($run['errors'] !== []) {
            $this->error(count($run['errors']).' case(s) could not be scored: '.implode(', ', $run['errors']).'.');
            $this->error('Nothing was saved. Run the command again: answered cases are cached, so only these are retried.');

            return self::FAILURE;
        }

        $s = $run['summary'];
        $this->newLine();
        $this->info(MerchantEvalRunner::SPLITS[$split].' (candidates, not Local Goods participants)');
        $this->table(['Measure', 'Result'], [
            ['Eligible yes/no agreement', MerchantEvalReport::rate($s['agreement'])],
            ['Baseline: always "not eligible"', MerchantEvalReport::rate($s['baseline_always_not_eligible'])],
            ['Independent found (of Finn\'s independent)', MerchantEvalReport::rate($s['recall'])],
            ['Eligible calls right (of the AI\'s eligible)', MerchantEvalReport::rate($s['precision'])],
            ['Exact classification match', MerchantEvalReport::rate($s['class_match'])],
            ['AI undetermined on decided cases', MerchantEvalReport::rate($s['undetermined']['ai_undetermined_on_decided'])],
            ['Triage: chains correctly not eligible', MerchantEvalReport::rate($s['triage']['chains_not_eligible'])],
            ['Triage: chains wrongly called eligible', MerchantEvalReport::rate($s['triage']['chains_called_eligible'])],
            ['Triage: routed to human review', MerchantEvalReport::rate($s['triage']['routed_to_review'])],
        ]);
        $this->table(['Finn ↓ / AI →', ...array_values(MerchantEvalReport::OUTCOME_NAMES)], array_map(
            fn ($label, $row) => [MerchantEvalReport::LABEL_NAMES[$label], ...array_values($row)],
            array_keys($s['matrix']), $s['matrix'],
        ));
        $this->line("Disagreements: {$s['disagreements']}, of which {$s['category_disagreements']} are category disagreements (both mean not eligible). Listed in the results file.");

        $paths = $report->save($run, $dir, config('eligibility.assessments_path'));
        $this->newLine();
        $this->info('Saved: '.implode(', ', array_map(fn ($p) => str_replace(base_path().'/', '', $p), $paths)));

        return self::SUCCESS;
    }
}
