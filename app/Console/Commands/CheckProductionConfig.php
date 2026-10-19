<?php

namespace App\Console\Commands;

use App\Support\ProductionConfig;
use Illuminate\Console\Command;

/**
 * Run by the Beanstalk deploy hook before migrating. Fails (and so stops the
 * deploy or configuration change, leaving the running site alone) when a
 * production setting is unsafe. Never prints a setting's value.
 */
class CheckProductionConfig extends Command
{
    protected $signature = 'app:check-production-config';

    protected $description = 'Stop a deploy whose production settings are unsafe (APP_DEBUG, APP_ENV, secure cookies, CloudFront secret)';

    public function handle(): int
    {
        $problems = ProductionConfig::problems();

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($problems !== []) {
            return self::FAILURE;
        }

        $this->info('Production settings are safe.');

        return self::SUCCESS;
    }
}
