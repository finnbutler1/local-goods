<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Employee;
use App\Models\MerchantProduct;
use App\Models\Partnership;
use App\Models\Redemption;
use App\Models\User;
use App\Services\DemoResetter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The deploy hook runs demo:seed-if-empty after every deploy. It must seed a
 * brand-new database once and never touch a database that already has data.
 */
class SeedDemoIfEmptyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_an_empty_database(): void
    {
        $this->artisan('demo:seed-if-empty')
            ->expectsOutputToContain('seeded the demo data')
            ->assertSuccessful();

        $this->assertSame(DatabaseSeeder::ENROLLED, Employee::count());
        $this->assertGreaterThan(12, Redemption::count());
    }

    public function test_running_twice_does_not_duplicate_the_demo(): void
    {
        $this->artisan('demo:seed-if-empty')->assertSuccessful();
        $redemptions = Redemption::count();
        $this->artisan('demo:seed-if-empty')
            ->expectsOutputToContain('nothing changed')
            ->assertSuccessful();

        $this->assertSame(1, Bid::count());
        $this->assertSame($redemptions, Redemption::count());
    }

    public function test_keeps_existing_data_and_other_tables(): void
    {
        $this->seed(DatabaseSeeder::class);
        $redemptions = Redemption::count();
        Redemption::firstOrFail()->update(['status' => Redemption::STATUS_UNDER_REVIEW]);
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'keep-me', 'payload' => 'x', 'last_activity' => 0]);

        $this->artisan('demo:seed-if-empty')->assertSuccessful();

        $this->assertSame($redemptions, Redemption::count());
        $this->assertSame(1, Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->count());
        $this->assertTrue(User::whereKey($user->id)->exists());
        $this->assertTrue(DB::table('sessions')->where('id', 'keep-me')->exists());
    }

    public function test_adds_only_the_menus_to_a_demo_seeded_before_them(): void
    {
        $this->seed(DatabaseSeeder::class);
        DB::table('merchant_products')->delete();
        $before = [Redemption::count(), DB::table('credit_ledger_entries')->count(), Employee::firstOrFail()->balanceCents()];

        $this->artisan('demo:seed-if-empty')
            ->expectsOutputToContain('product menus')
            ->assertSuccessful();

        $this->assertSame(collect(DatabaseSeeder::DEMO_MENUS)->flatten(1)->count(), MerchantProduct::count());
        $this->assertSame($before, [Redemption::count(), DB::table('credit_ledger_entries')->count(), Employee::firstOrFail()->balanceCents()]);
        $this->artisan('demo:seed-if-empty')->expectsOutputToContain('nothing changed')->assertSuccessful();
    }

    public function test_adds_only_the_district_extras_to_a_demo_seeded_before_them(): void
    {
        $this->seed(DatabaseSeeder::class);
        foreach (DemoResetter::DISTRICT_TABLES as $table) {
            DB::table($table)->delete();
        }
        $before = [Redemption::count(), DB::table('credit_ledger_entries')->count(), Employee::firstOrFail()->balanceCents(), MerchantProduct::count()];

        $this->artisan('demo:seed-if-empty')
            ->expectsOutputToContain('Added the demo partnership')
            ->assertSuccessful();

        $this->assertTrue(Partnership::sole()->is_demo);
        $this->assertSame($before, [Redemption::count(), DB::table('credit_ledger_entries')->count(), Employee::firstOrFail()->balanceCents(), MerchantProduct::count()]);
        $this->artisan('demo:seed-if-empty')->expectsOutputToContain('nothing changed')->assertSuccessful();
    }

    public function test_a_real_application_does_not_stop_the_demo_partnership_being_added(): void
    {
        // Finn's local demo (2026-10-28): migrated, then someone applied through /apply before seed-if-empty ran.
        $this->seed(DatabaseSeeder::class);
        foreach (DemoResetter::DISTRICT_TABLES as $table) {
            DB::table($table)->delete();
        }
        app(\App\Services\Districts\MerchantApplications::class)->submit(['business_name' => 'K Street Gym', 'address' => '1700 K St NW',
            'category' => 'fitness', 'contact_email' => 'gym@example.com', 'independence_answer' => 'One gym, owned by its two trainers.']);

        $this->artisan('demo:seed-if-empty')
            ->expectsOutputToContain('Added the demo partnership and the two sample applications; nothing else changed.')
            ->assertSuccessful();

        $this->assertTrue(Partnership::sole()->is_demo);
        $this->assertSame(2, \App\Models\MerchantApplication::where('is_sample', true)->count());
        $this->assertSame(1, \App\Models\MerchantApplication::where('is_sample', false)->count(), 'the real application is kept');
        $this->artisan('demo:seed-if-empty')->expectsOutputToContain('nothing changed')->assertSuccessful();
    }

    public function test_only_the_missing_extra_is_added(): void
    {
        $this->seed(DatabaseSeeder::class);
        DB::table('partnership_changes')->delete();
        DB::table('partnerships')->delete();

        $this->artisan('demo:seed-if-empty')->expectsOutputToContain('Added the demo partnership; nothing else changed.')->assertSuccessful();

        $this->assertSame(1, Partnership::count());
        $this->assertSame(2, \App\Models\MerchantApplication::where('is_sample', true)->count(), 'the samples are not added twice');
    }

    public function test_leaves_a_partly_filled_database_alone(): void
    {
        Bid::create(['name' => 'Golden Triangle', 'city' => 'Washington, DC']);

        $this->artisan('demo:seed-if-empty')
            ->expectsOutputToContain('Left everything as is')
            ->assertSuccessful();

        $this->assertSame(1, Bid::count());
        $this->assertSame(0, Employee::count());
    }
}
