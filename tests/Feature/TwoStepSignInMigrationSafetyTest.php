<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;
use Tests\Concerns\RollsBackMigrations;
use Tests\TestCase;

/**
 * The two-step sign-in migration runs on the live RDS database. It only adds
 * three nullable columns to users: this rolls it back and runs it again on the
 * seeded demo and checks nothing else about users changed. Runs on SQLite and
 * in CI's MySQL job.
 */
class TwoStepSignInMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;
    use RollsBackMigrations;

    private const MIGRATION = 'database/migrations/2026_11_13_000001_add_two_factor_columns_to_users_table.php';

    private const COLUMNS = ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'];

    public function test_it_only_adds_three_nullable_columns_and_changes_no_row(): void
    {
        $this->seed(DatabaseSeeder::class);
        User::factory()->role(Role::PlatformAdmin)->create();
        $columnsWith = Schema::getColumnListing('users');
        $before = $this->users();

        $this->rollBackSince(self::MIGRATION);
        $columnsWithout = Schema::getColumnListing('users');
        $this->assertEqualsCanonicalizing(self::COLUMNS, array_values(array_diff($columnsWith, $columnsWithout)));
        $this->assertSame([], array_diff($columnsWithout, $columnsWith), 'Rolling back removes only the three columns.');
        $this->assertSame($before, $this->users());

        $this->migrateAgain();

        $this->assertEqualsCanonicalizing($columnsWith, Schema::getColumnListing('users'));
        $this->assertSame($before, $this->users());
        foreach (Schema::getColumns('users') as $column) {
            if (in_array($column['name'], self::COLUMNS, true)) {
                $this->assertTrue($column['nullable'], "{$column['name']} is nullable");
            }
        }
        $this->assertSame(0, DB::table('users')->where(fn ($q) => $q->whereNotNull('two_factor_secret')
            ->orWhereNotNull('two_factor_recovery_codes')->orWhereNotNull('two_factor_confirmed_at'))->count());
    }

    /**
     * Rolling back the code but not the migration: code without the feature
     * never reads the columns, so a user who had it on signs in with their
     * password alone. (Approximated here by switching the feature off; the
     * real check against the previous commit is in the CLAUDE.md milestone.)
     */
    public function test_with_the_feature_off_the_columns_are_ignored_at_sign_in(): void
    {
        $user = User::factory()->role(Role::PlatformAdmin)->create(['password' => 'fake-example-password-1']);
        $user->forceFill(['two_factor_secret' => encrypt('FAKEEXAMPLESECRET'), 'two_factor_confirmed_at' => now()])->save();
        config(['fortify.features' => array_values(array_filter(config('fortify.features'), fn ($f) => $f !== Features::twoFactorAuthentication()))]);

        $this->post('/login', ['email' => $user->email, 'password' => 'fake-example-password-1'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    /** @return list<array<string, string>> every users row, every column except the new ones */
    private function users(): array
    {
        return DB::table('users')->orderBy('id')->get()
            ->map(fn ($row) => array_map(fn ($v) => (string) $v, array_diff_key((array) $row, array_flip(self::COLUMNS))))
            ->all();
    }
}
