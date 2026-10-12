<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Employee;
use App\Models\Redemption;
use App\Models\User;
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

        $this->assertSame(1, Employee::count());
        $this->assertSame(12, Redemption::count());
    }

    public function test_running_twice_does_not_duplicate_the_demo(): void
    {
        $this->artisan('demo:seed-if-empty')->assertSuccessful();
        $this->artisan('demo:seed-if-empty')
            ->expectsOutputToContain('nothing changed')
            ->assertSuccessful();

        $this->assertSame(1, Bid::count());
        $this->assertSame(12, Redemption::count());
    }

    public function test_keeps_existing_data_and_other_tables(): void
    {
        $this->seed(DatabaseSeeder::class);
        Redemption::firstOrFail()->update(['status' => Redemption::STATUS_UNDER_REVIEW]);
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'keep-me', 'payload' => 'x', 'last_activity' => 0]);

        $this->artisan('demo:seed-if-empty')->assertSuccessful();

        $this->assertSame(12, Redemption::count());
        $this->assertSame(1, Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->count());
        $this->assertTrue(User::whereKey($user->id)->exists());
        $this->assertTrue(DB::table('sessions')->where('id', 'keep-me')->exists());
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
