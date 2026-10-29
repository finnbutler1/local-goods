<?php

use App\Models\Bid;
use App\Models\District;
use App\Models\Merchant;
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
    $seeded = array_diff(DemoResetter::DEMO_TABLES, DemoResetter::EMPTY_AFTER_SEED);
    $filled = array_filter($seeded, fn ($table) => DB::table($table)->exists());

    // The menus came after the live demo was seeded: add only them (ADR 0023).
    $menuless = array_diff($seeded, [DemoResetter::MENUS_TABLE]);
    if (! in_array(DemoResetter::MENUS_TABLE, $filled, true) && count($filled) === count($menuless)
        && Merchant::whereIn('name', array_column(DatabaseSeeder::DEMO_MERCHANTS, 0))->count() === count(DatabaseSeeder::DEMO_MERCHANTS)) {
        DB::transaction(fn () => DatabaseSeeder::seedMenus(collect(DatabaseSeeder::DEMO_MERCHANTS)
            ->map(fn ($m) => Merchant::where('name', $m[0])->firstOrFail())));
        $this->info('Added the demo merchants\' product menus; nothing else changed.');

        return;
    }

    // ADR 0031 came after the live demo was seeded: to a demo that is otherwise
    // there, add the demo partnership and the sample applications, each only if
    // missing. Real applications (sent through /apply) don't stop either.
    $districtless = array_diff($seeded, DemoResetter::DISTRICT_TABLES);
    if (count(array_intersect($districtless, $filled)) === count($districtless)) {
        $added = DB::transaction(fn () => DatabaseSeeder::seedMissingDistrictExtras(Bid::firstOrFail(), District::default()));
        if ($added !== []) {
            $this->info('Added '.implode(' and ', $added).'; nothing else changed.');

            return;
        }
    }

    if ($filled === []) {
        DB::transaction(fn () => (new DatabaseSeeder)->run());
        $this->info('Demo tables were empty: seeded the demo data.');
    } elseif (count($filled) === count($seeded)) {
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
