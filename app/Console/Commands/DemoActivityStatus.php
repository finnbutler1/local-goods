<?php

namespace App\Console\Commands;

use App\Services\DemoActivity\ActivityStatus;
use App\Support\Money;
use App\Support\WaitingForAPerson;
use Illuminate\Console\Command;

/** What the demo activity generator has done (ADR 0032). Reads only. */
class DemoActivityStatus extends Command
{
    protected $signature = 'demo-activity:status';

    protected $description = 'Show what the demo activity generator has done: runs, actions, totals, failures and the kill switch';

    public function handle(ActivityStatus $status): int
    {
        $s = $status->summary();
        $waiting = WaitingForAPerson::summary();

        $this->line('Kill switch: DEMO_ACTIVITY_ENABLED is '.($s['enabled'] ? 'true (on)' : 'false (off)').'; stops by itself after '.$s['ends_on']->toDateString().'.');
        $this->line('Right now: '.($s['refusal'] ?? 'it would run.'));
        $this->line('Today: '.$s['today']['done'].' done, '.$s['today']['skipped'].' skipped, '.$s['today']['failed'].' failed of '.$s['today']['planned'].' planned'
            .($s['today']['halted'] ? '. Stopped: '.$s['today']['halted'] : '.'));
        $this->line('Holds seen: '.$s['holds']['held'].' of '.$s['holds']['redemptions'].' generated redemptions ('.$s['holds']['unusual_held'].' of '.$s['holds']['unusual'].' planned as unusual).');
        $this->line('Waiting for a person: '.$waiting['held'].' held, '.$waiting['refunds'].' refund requests'.($waiting['overdue'] ? ' (something has waited over '.$waiting['overdue_days'].' days)' : '').'.');

        $this->table(
            ['Day', 'Actions', 'Redemptions', 'In person', 'Spent', 'Bought', 'Held', 'Transfers', 'Failed', 'Reconciliation', 'Stopped'],
            $s['days']->map(fn ($d) => [
                $d['date']->format('D M j'), $d['actions'], $d['redemptions'], $d['in_person'], Money::format($d['spend_cents']), Money::format($d['purchase_cents']),
                $d['held'], $d['transfers'], $d['failed'], $d['reconciliation'] === null ? '—' : ($d['reconciliation'] ? 'matches' : 'MISMATCH'), $d['halted'] ?? '',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
