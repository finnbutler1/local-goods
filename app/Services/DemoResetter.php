<?php

namespace App\Services;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Restores the seeded demo. Only the Local Goods demo tables are cleared;
 * Laravel's own tables (users, cache, jobs, sessions, migrations) and
 * stripe_events are untouched. Demo merchants keep their Stripe test accounts.
 */
class DemoResetter
{
    /**
     * Children before parents, so foreign keys are never violated.
     */
    public const DEMO_TABLES = [
        'credit_ledger_entries',
        'redemption_decisions',
        'merchant_payouts',
        'credit_allocations',
        'credit_purchases',
        'redemptions',
        'merchants',
        'employees',
        'employers',
        'bids',
    ];

    /**
     * Demo tables the seed leaves empty: decisions only exist once a person
     * reviews a held redemption, so demo:seed-if-empty doesn't count them.
     */
    public const EMPTY_AFTER_SEED = ['redemption_decisions'];

    /** Stripe connection columns kept across a reset, matched by merchant name (ADR 0015). */
    private const KEPT_STRIPE_COLUMNS = [
        'stripe_account_id', 'stripe_onboarding_key', 'stripe_details_submitted',
        'stripe_payouts_enabled', 'stripe_transfers_active', 'stripe_synced_at', 'stripe_set_up_by_platform',
    ];

    public function reset(): void
    {
        DB::transaction(function () {
            // A demo merchant's Stripe test account outlives the reset, so
            // Farragut Grind doesn't need a new Express account every time.
            $stripe = DB::table('merchants')->whereNotNull('stripe_account_id')
                ->get(['name', ...self::KEPT_STRIPE_COLUMNS])->keyBy('name');

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

            foreach ($stripe as $name => $row) {
                DB::table('merchants')->where('name', $name)->update(array_intersect_key((array) $row, array_flip(self::KEPT_STRIPE_COLUMNS)));
            }
        });
    }
}
