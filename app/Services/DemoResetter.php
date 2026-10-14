<?php

namespace App\Services;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Restores the seeded demo. Only the Local Goods demo tables are cleared;
 * Laravel's own tables (users, cache, jobs, sessions, migrations) are untouched.
 */
class DemoResetter
{
    /**
     * Children before parents, so foreign keys are never violated.
     */
    public const DEMO_TABLES = [
        'credit_ledger_entries',
        'merchant_payouts',
        'credit_allocations',
        'credit_purchases',
        'redemptions',
        'merchants',
        'employees',
        'employers',
        'bids',
    ];

    public function reset(): void
    {
        DB::transaction(function () {
            foreach (self::DEMO_TABLES as $table) {
                // Query-builder delete on purpose: the ledger model refuses
                // deletes, and this demo reset is the one sanctioned exception.
                DB::table($table)->delete();
            }

            if (DB::getDriverName() === 'sqlite') {
                // Restart ids at 1 so the reset demo looks like a fresh deploy.
                DB::table('sqlite_sequence')->whereIn('name', self::DEMO_TABLES)->delete();
            }
            // On MySQL ids keep counting up after a reset. Resetting them needs
            // ALTER TABLE, which silently commits this transaction midway.

            (new DatabaseSeeder)->run();
        });
    }
}
