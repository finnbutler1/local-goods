<?php

namespace App\Console\Commands;

use App\Services\Geo\AddressLookup;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deletes merchant applications made before a date, with their steps, and
 * address checker lookups older than 30 days (ADR 0031). Applications are
 * deleted on request or after config('applications.retain_until'): run it
 * on 2027-01-01 with --before=2027-01-01, or with a later date to honour a
 * request. Sample applications are kept. A merchant made from a deleted
 * application stays; only the application and its steps go.
 *
 * Query-builder deletes on purpose: the step log refuses deletes through
 * its model, and this retention clean-up is the one sanctioned exception
 * (like DemoResetter). Run by hand; nothing schedules it.
 */
class PurgeApplications extends Command
{
    protected $signature = 'applications:purge
        {--before= : Delete applications made before this date (YYYY-MM-DD, DC time)}
        {--dry-run : Only count what would be deleted}';

    protected $description = 'Delete merchant applications made before a date and address checker lookups older than 30 days';

    public function handle(): int
    {
        $before = (string) $this->option('before');
        try {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) {
                throw new \InvalidArgumentException;
            }
            $cutoff = Carbon::createFromFormat('Y-m-d', $before, DatabaseSeeder::TIMEZONE)->startOfDay()->utc();
        } catch (Throwable) {
            $this->error('Give --before=YYYY-MM-DD: applications made before that date are deleted.');

            return self::FAILURE;
        }

        $applications = DB::table('merchant_applications')->where('is_sample', false)->where('created_at', '<', $cutoff);
        $ids = $applications->pluck('id');
        $events = DB::table('merchant_application_events')->whereIn('merchant_application_id', $ids);
        $lookups = DB::table('address_lookups')->where('origin', AddressLookup::ORIGIN_CHECKER)
            ->where('looked_up_at', '<', now()->subDays((int) config('applications.checker_lookup_days', 30)));

        $counts = [$ids->count(), $events->count(), $lookups->count()];

        if (! $this->option('dry-run')) {
            DB::transaction(function () use ($events, $ids, $lookups) {
                $events->delete();
                DB::table('merchant_applications')->whereIn('id', $ids)->delete();
                $lookups->delete();
            });
        }

        $verb = $this->option('dry-run') ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$counts[0]} application(s) made before {$before} (DC time) with {$counts[1]} step(s), and {$counts[2]} address checker lookup(s) older than "
            .config('applications.checker_lookup_days', 30).' days. Sample applications are kept.');

        return self::SUCCESS;
    }
}
