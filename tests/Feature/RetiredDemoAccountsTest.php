<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use App\Support\RetiredDemoAccounts;
use Closure;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The old "Casey Brooks" demo judge is removed once: deleted if nothing
 * points at it, otherwise revoked. demo:seed-accounts never brings it back.
 */
class RetiredDemoAccountsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_15_000001_remove_retired_demo_judge.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function casey(): User
    {
        $user = User::factory()->role(Role::Judge)->create(['name' => 'Casey Brooks', 'email' => 'casey.brooks@example.com']);
        $user->forceFill(['invite_token_hash' => hash('sha256', 'x')])->save();

        return $user;
    }

    private function runMigration(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    public function test_the_migration_deletes_casey_brooks_for_good_when_nothing_points_at_it(): void
    {
        $casey = $this->casey();
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $casey->id, 'payload' => '', 'last_activity' => time()]);

        $this->runMigration();

        $this->assertDatabaseMissing('users', ['email' => 'casey.brooks@example.com']);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
    }

    public static function attachedRecords(): array
    {
        return [
            'feedback' => [fn (User $u) => $u->feedback()->create([
                'page_url' => 'http://localhost/judge', 'category' => 'functionality', 'rating' => 3,
                'severity' => 'should_fix', 'comment' => 'Synthetic.',
            ])],
            'employee wallet' => [fn (User $u) => Employee::firstOrFail()->update(['user_id' => $u->id])],
            'redemption decided by id' => [fn (User $u) => DB::table('redemptions')->limit(1)->update(['reviewed_by_user_id' => $u->id])],
            'redemption decided by name' => [fn (User $u) => DB::table('redemptions')->limit(1)->update(['reviewed_by' => $u->name])],
            'ledger entry decided by name' => [fn (User $u) => DB::table('credit_ledger_entries')->limit(1)->update(['decided_by' => $u->name])],
        ];
    }

    #[DataProvider('attachedRecords')]
    public function test_casey_brooks_is_kept_but_revoked_when_records_point_at_it(Closure $attach): void
    {
        $casey = $this->casey();
        $attach($casey);
        $feedbackBefore = DB::table('feedback')->count();

        $this->runMigration();

        $kept = User::where('email', 'casey.brooks@example.com')->sole();
        $this->assertTrue($kept->isRevoked());
        $this->assertNull($kept->invite_token_hash);
        $this->assertSame($feedbackBefore, DB::table('feedback')->count(), 'No record is lost.');
    }

    public function test_it_only_touches_the_old_judge_never_another_login(): void
    {
        $merchant = User::factory()->role(Role::Merchant)->create(['email' => 'casey.brooks@example.com']);
        $judge = User::factory()->role(Role::Judge)->create(['name' => 'Casey Brooks']); // same name, other email

        $this->assertSame(['deleted' => 0, 'revoked' => 0], RetiredDemoAccounts::cleanUp());
        $this->assertFalse($merchant->fresh()->isRevoked());
        $this->assertNotNull($judge->fresh());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->casey();
        RetiredDemoAccounts::cleanUp();

        $this->assertSame(['deleted' => 0, 'revoked' => 0], RetiredDemoAccounts::cleanUp());
    }

    public function test_seeding_accounts_never_recreates_casey_brooks(): void
    {
        foreach (config('demo.accounts') as $role => $account) {
            config(["demo.accounts.$role.password" => 'test-only-password']);
        }

        // Today's config: no Casey Brooks.
        $this->artisan('demo:seed-accounts')->assertSuccessful();
        $this->assertDatabaseMissing('users', ['email' => 'casey.brooks@example.com']);

        // Even if the old judge entry came back into the config, it is skipped.
        config(['demo.accounts.judge' => ['name' => 'Casey Brooks', 'email' => 'casey.brooks@example.com', 'password' => 'test-only-password']]);
        $this->artisan('demo:seed-accounts')
            ->expectsOutputToContain('Skipped judge: that demo login is retired.')
            ->assertSuccessful();
        $this->assertDatabaseMissing('users', ['email' => 'casey.brooks@example.com']);
        $this->assertDatabaseMissing('users', ['name' => 'Casey Brooks']);
    }
}
