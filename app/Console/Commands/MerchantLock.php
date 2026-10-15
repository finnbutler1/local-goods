<?php

namespace App\Console\Commands;

use App\Services\Eligibility\MerchantCandidates;
use App\Services\Eligibility\MerchantLabelLock;
use App\Services\Eligibility\MerchantLabels;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * Writes data/merchant_labels_lock.json. Run once, after the labels are
 * committed and before Merchant Eligibility Matching is run on them. Checks
 * that the label file on disk matches the labeling commit and is fully
 * labeled, so the lock points at the exact labels in git history.
 */
class MerchantLock extends Command
{
    protected $signature = 'merchant:lock
        {--commit= : The commit with the final labels (default: the last commit that changed the label file)}
        {--force : Replace an existing lock}';

    protected $description = 'Lock the hand-labeled merchant ground truth before the AI sees it';

    private const LABELS = 'data/merchant_labels.csv';

    public function handle(): int
    {
        if (MerchantLabelLock::load() !== null && ! $this->option('force')) {
            $this->error('The labels are already locked. Changing the lock after an AI run would defeat it.');

            return self::FAILURE;
        }

        $commit = $this->option('commit')
            ?: trim(Process::path(base_path())->run(['git', 'log', '-1', '--format=%H', '--', self::LABELS])->output());
        $committed = Process::path(base_path())->run(['git', 'show', "{$commit}:".self::LABELS]);

        if ($commit === '' || $committed->failed()) {
            $this->error("Could not read the label file at commit '{$commit}'.");

            return self::FAILURE;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'merchant-lock');
        file_put_contents($tmp, $committed->output());

        try {
            $rows = MerchantLabels::load();
            $atCommit = MerchantLabels::load($tmp);
            $problems = MerchantLabels::problems($rows, MerchantCandidates::load(), requireLabels: true);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            unlink($tmp);
        }

        if ($problems !== []) {
            $this->error('The label file is not ready to lock:');
            foreach ($problems as $problem) {
                $this->line("  {$problem}");
            }

            return self::FAILURE;
        }

        $lock = MerchantLabelLock::build($rows, $commit, now(config('fraud.timezone'))->toIso8601String());

        if (MerchantLabelLock::problems($atCommit, $lock) !== []) {
            $this->error("The label file differs from commit {$commit}. Commit the labels first.");

            return self::FAILURE;
        }

        file_put_contents(MerchantLabelLock::path(), json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info("Locked {$lock['row_count']} labeled merchants at commit {$commit}.");

        return self::SUCCESS;
    }
}
