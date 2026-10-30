<?php

namespace App\Console\Commands;

use App\Services\DemoActivity\ActivityGenerator;
use App\Services\DemoActivity\ActivityGuard;
use App\Services\DemoActivity\DayPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * One tick of the demo activity generator (ADR 0032). The scheduler runs it
 * every five minutes on weekdays; it does nothing unless
 * DEMO_ACTIVITY_ENABLED is true and Stripe is in test mode. --dry-run
 * prints today's plan and touches nothing. Always exits 0, so cron never
 * sees a failure: problems are on the action rows and in the log.
 */
class DemoActivityTick extends Command
{
    protected $signature = 'demo-activity:tick {--dry-run : Print today\'s plan and do nothing}';

    protected $description = 'Carry out the due slots of today\'s demo activity plan (Stripe test mode only)';

    public function handle(ActivityGenerator $generator, DayPlanner $planner): int
    {
        if ($this->option('dry-run')) {
            $today = CarbonImmutable::now(ActivityGuard::TIMEZONE)->startOfDay();
            $this->line('Plan for '.$today->format('l, F j').' (nothing is done in a dry run):');
            foreach ($planner->plan($today) as $slot) {
                $what = isset($slot['merchant'])
                    ? $slot['merchant'].', '.str_replace('_', ' ', $slot['channel']).', '
                        .($slot['items'] !== null ? implode(' + ', array_map(fn ($n, $q) => $q.'× '.$n, array_keys($slot['items']), $slot['items'])) : '$'.number_format($slot['amount_cents'] / 100, 2))
                        .($slot['unusual'] ? ' (planned as unusual)' : '')
                    : '';
                $this->line('  '.$slot['at'].'  '.str_pad($slot['slot'], 12).' '.$slot['kind'].($what !== '' ? ': '.$what : ''));
            }
            $this->line('Would it run now? '.(ActivityGuard::refusal() ?? 'Yes.'));

            return self::SUCCESS;
        }

        try {
            foreach ($generator->tick() as $line) {
                $this->line($line);
            }
        } catch (Throwable $e) {
            $this->line('Tick failed: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
