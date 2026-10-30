<?php

namespace App\Console\Commands;

use App\Models\SolvencyCheckRun;
use App\Services\Solvency\SolvencyCheck;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * The solvency rule (ADR 0033) over the whole ledger history, moment by
 * moment, so a moment of insolvency in the past can't be hidden by a later
 * correction. Reads the ledger only; stores the result on solvency_checks
 * unless --no-record. Exits 1 if the rule failed at any moment.
 */
class CheckSolvency extends Command
{
    protected $signature = 'ledger:check-solvency
        {--trigger=command : Who ran it, stored with the result (command, deploy)}
        {--no-record : Print only; store nothing}';

    protected $description = 'Check that money in hand covered every promise at every moment in the ledger history';

    public function handle(SolvencyCheck $check): int
    {
        $trigger = (string) $this->option('trigger');
        if (! in_array($trigger, [SolvencyCheckRun::TRIGGER_COMMAND, SolvencyCheckRun::TRIGGER_DEPLOY], true)) {
            $this->error('--trigger is command or deploy.');

            return self::INVALID;
        }

        $history = $check->history();
        $end = $history->final;

        $this->line($history->summary());
        $this->line('From '.($history->firstEntryAt ?? '—').' to '.($history->lastEntryAt ?? '—').' UTC, in '.$history->durationMs.' ms.');
        $this->line('Now: customer funds held '.Money::format($end->customerFundsCents()).', operating funds '.Money::format($end->operatingCents)
            .', money in hand '.Money::format($end->inHandCents()).'.');

        foreach (array_slice($history->windows, 0, 10) as $window) {
            $accounts = implode(', ', array_map(SolvencyCheck::accountLabel(...), $window['accounts']));
            $this->warn('Failed from '.$window['from'].' to '.($window['to'] ?? 'now').' UTC: '.$window['problem']
                .'. Worst '.Money::format($window['worst_cents']).' at '.$window['worst_at'].($accounts === '' ? '' : '. Below zero: '.$accounts).'.');
        }
        foreach ($history->mismatches as $mismatch) {
            $this->warn($mismatch);
        }

        if (! $this->option('no-record')) {
            SolvencyCheckRun::record($history, $trigger);
        }

        return $history->passed() ? self::SUCCESS : self::FAILURE;
    }
}
