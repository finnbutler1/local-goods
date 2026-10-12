<?php

use App\Services\DemoResetter;
use Database\Seeders\DatabaseSeeder;
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
