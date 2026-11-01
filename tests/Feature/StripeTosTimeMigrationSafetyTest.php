<?php

namespace Tests\Feature;

use App\Models\Merchant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RollsBackMigrations;
use Tests\TestCase;

/**
 * merchants.stripe_tos_accepted_at runs on the live RDS database. This
 * rolls it back and runs it again on the seeded demo and checks every
 * merchant's Stripe connection is unchanged and the new column starts
 * empty. Runs on SQLite and in CI's MySQL job.
 */
class StripeTosTimeMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;

    private const MIGRATION = 'database/migrations/2026_11_03_000001_add_stripe_tos_accepted_at_to_merchants.php';

    public function test_existing_merchants_and_their_stripe_connections_are_unchanged(): void
    {
        $this->seed(DatabaseSeeder::class);
        Merchant::where('name', 'Farragut Grind')->update(['stripe_account_id' => 'acct_test_fake_example', 'stripe_onboarding_key' => 'fake-key-example']);
        $before = $this->snapshot();

        $this->rollBackSince(self::MIGRATION);
        $this->assertFalse(Schema::hasColumn('merchants', 'stripe_tos_accepted_at'));

        $this->migrateAgain();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, Merchant::whereNotNull('stripe_tos_accepted_at')->count());
    }

    /** @return list<array<string, string>> */
    private function snapshot(): array
    {
        return Merchant::orderBy('id')->get(['id', 'name', 'stripe_account_id', 'stripe_onboarding_key', 'stripe_set_up_by_platform'])
            ->map(fn (Merchant $m) => array_map(fn ($v) => (string) $v, $m->only(['id', 'name', 'stripe_account_id', 'stripe_onboarding_key', 'stripe_set_up_by_platform'])))->all();
    }
}
