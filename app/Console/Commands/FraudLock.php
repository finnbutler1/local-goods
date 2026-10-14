<?php

namespace App\Console\Commands;

use App\Services\Fraud\FraudEvalLock;
use App\Services\Fraud\FraudEvalRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * Writes data/fraud_eval_lock.json for the holdout_3 cases. Run once, after
 * the labels are committed and before the AI sees the cases. Checks that the
 * cases file on disk matches the labeling commit, so the lock points at the
 * exact labels in git history.
 */
class FraudLock extends Command
{
    protected $signature = 'fraud:lock
        {--commit= : The commit with the final labels (default: the last commit that changed the cases file)}
        {--force : Replace an existing lock}';

    protected $description = 'Lock the holdout_3 fraud eval cases before the AI sees them';

    private const CASES = 'data/fraud_eval_cases.csv';

    public function handle(FraudEvalRunner $runner): int
    {
        if (FraudEvalLock::load() !== null && ! $this->option('force')) {
            $this->error('The cases are already locked. Changing the lock after an AI run would defeat it.');

            return self::FAILURE;
        }

        $commit = $this->option('commit')
            ?: trim(Process::path(base_path())->run(['git', 'log', '-1', '--format=%H', '--', self::CASES])->output());
        $committed = Process::path(base_path())->run(['git', 'show', "{$commit}:".self::CASES]);

        if ($commit === '' || $committed->failed()) {
            $this->error("Could not read the cases file at commit '{$commit}'.");

            return self::FAILURE;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'fraud-lock');
        file_put_contents($tmp, $committed->output());

        try {
            $cases = $runner->loadCases(base_path(self::CASES));
            $atCommit = $runner->loadCases($tmp);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            unlink($tmp);
        }

        $lock = FraudEvalLock::build($cases, $commit, now(config('fraud.timezone'))->toIso8601String());

        if (FraudEvalLock::problems($atCommit, $lock) !== []) {
            $this->error("The cases file differs from commit {$commit}. Commit the labels first.");

            return self::FAILURE;
        }
        if ($lock['case_count'] === 0) {
            $this->error('There are no '.FraudEvalLock::SPLIT.' cases to lock.');

            return self::FAILURE;
        }

        file_put_contents(FraudEvalLock::path(), json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info("Locked {$lock['case_count']} ".FraudEvalLock::SPLIT." cases at commit {$commit} "
            ."({$lock['labels']['suspicious']} suspicious, {$lock['labels']['normal']} normal).");

        return self::SUCCESS;
    }
}
