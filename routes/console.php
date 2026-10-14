<?php

use App\Services\DemoResetter;
use App\Support\RetiredDemoAccounts;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run by the Beanstalk deploy hook. Seeds the demo only into an empty
// database, so deploying never overwrites or duplicates existing data.
Artisan::command('demo:seed-if-empty', function () {
    $filled = array_filter(DemoResetter::DEMO_TABLES, fn ($table) => DB::table($table)->exists());

    if ($filled === []) {
        DB::transaction(fn () => (new DatabaseSeeder)->run());
        $this->info('Demo tables were empty: seeded the demo data.');
    } elseif (count($filled) === count(DemoResetter::DEMO_TABLES)) {
        $this->info('Demo data already present: nothing changed.');
    } else {
        $this->warn('Some demo tables are empty and some are not ('.implode(', ', $filled).' have rows). Left everything as is; use Reset demo to restore it.');
    }
})->purpose('Seed the demo data only if the demo tables are empty');

// Run by the Beanstalk deploy hook. Creates or updates one login per role.
// Passwords come from DEMO_PASSWORD_* and are never printed. The judge is
// the shared Public judge access reviewer, which has no password.
Artisan::command('demo:seed-accounts', function () {
    $result = (new DemoAccountSeeder)->run();

    if ($result['saved'] !== []) {
        $this->info('Demo logins ready for: '.implode(', ', $result['saved']).'.');
    }

    foreach ($result['skipped'] as $role) {
        $this->warn(RetiredDemoAccounts::isRetired(config("demo.accounts.$role.email"))
            ? "Skipped {$role}: that demo login is retired."
            : "Skipped {$role}: DEMO_PASSWORD_".strtoupper($role).' is not set.');
    }
})->purpose('Create or update the demo login for each role from DEMO_PASSWORD_* settings');
