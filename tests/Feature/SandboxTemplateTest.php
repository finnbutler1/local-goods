<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Services\Sandbox\Sandbox;
use App\Services\Sandbox\SandboxFiles;
use App\Services\Sandbox\SandboxTemplateBuilder;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\UsesSandboxes;
use Tests\TestCase;

/**
 * sandbox:build-template (ADR 0045): a fresh file from every migration and
 * the demo seed, built without one query on the live connection, and with
 * nothing that weakens the production Reset demo block.
 */
class SandboxTemplateTest extends TestCase
{
    use RefreshDatabase, UsesSandboxes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSandboxes(withTemplate: false);
    }

    /** Reads the built file through its own connection, after the build. */
    private function inspect(string $path): Connection
    {
        config(['database.connections.inspect' => Sandbox::connectionConfig($path)]);
        DB::purge('inspect');

        return DB::connection('inspect');
    }

    public function test_the_build_makes_no_query_on_the_live_connection(): void
    {
        $main = DB::getDefaultConnection();
        $live = [];
        $built = 0;
        DB::listen(function (QueryExecuted $q) use (&$live, &$built) {
            $q->connectionName === SandboxTemplateBuilder::CONNECTION ? $built++ : $live[] = "[{$q->connectionName}] {$q->sql}";
        });

        $this->artisan('sandbox:build-template')->expectsOutputToContain("Built today's sandbox template")->assertSuccessful();

        $this->assertSame([], $live);
        $this->assertGreaterThan(500, $built, 'The build did run its migrations and seed');
        $this->assertSame($main, DB::getDefaultConnection(), 'The live connection is the default again afterwards');
        $this->assertFileExists(app(SandboxFiles::class)->templatePath());
        $this->assertSame([], glob($this->sandboxDir.'/*.tmp-*') ?: [], 'No half-written file is left');
    }

    public function test_the_template_has_every_migration_the_cache_tables_and_one_login_per_role(): void
    {
        $this->artisan('sandbox:build-template')->assertSuccessful();
        $db = $this->inspect(app(SandboxFiles::class)->templatePath());

        $this->assertSame(count(File::files(database_path('migrations'))), $db->table('migrations')->count());
        foreach (['cache', 'cache_locks'] as $table) {
            $this->assertTrue($db->getSchemaBuilder()->hasTable($table), "The sandbox cache store needs {$table}");
        }
        $this->assertSame(
            ['bid_admin@sandbox.invalid', 'employee@sandbox.invalid', 'employer@sandbox.invalid', 'merchant@sandbox.invalid', 'reviewer@sandbox.invalid'],
            $db->table('users')->orderBy('email')->pluck('email')->all(),
            'Only the five sandbox logins: no reviewer, judge or live account',
        );
        $this->assertSame('Jordan Rivera (demo)', $db->table('employees')->join('users', 'users.id', '=', 'employees.user_id')->where('users.email', 'employee@sandbox.invalid')->value('display_name'));
        $this->assertSame('Farragut Grind', $db->table('merchants')->join('users', 'users.id', '=', 'merchants.user_id')->where('users.email', 'merchant@sandbox.invalid')->value('merchants.name'));
        $this->assertSame(0, $db->table('feedback')->count());
        $this->assertSame(0, $db->table('stripe_events')->count());
        $this->assertGreaterThan(100, $db->table('credit_ledger_entries')->count());
        $this->assertSame('delete', $db->selectOne('pragma journal_mode')->journal_mode, 'One file, nothing beside it');
        DB::purge('inspect');
    }

    public function test_it_is_skipped_when_sandboxes_are_off_or_todays_exists(): void
    {
        config(['sandbox.enabled' => false]);
        $this->artisan('sandbox:build-template')->expectsOutputToContain('Sandboxes are off')->assertSuccessful();
        $this->assertFileDoesNotExist(app(SandboxFiles::class)->templatePath());

        config(['sandbox.enabled' => true]);
        $this->artisan('sandbox:build-template')->assertSuccessful();
        $this->artisan('sandbox:build-template')->expectsOutputToContain('already there')->assertSuccessful();
    }

    public function test_the_build_leaves_the_production_reset_demo_block_alone(): void
    {
        $admin = $this->signIn(Role::PlatformAdmin);
        $resetEnabled = config('demo.reset_enabled');

        $this->artisan('sandbox:build-template')->assertSuccessful();

        $this->assertSame($resetEnabled, config('demo.reset_enabled'));
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertFalse(Gate::forUser($admin)->allows('reset-demo'), 'Reset demo still refuses in production');
        foreach (['DemoResetter', 'reset-demo', 'reset_enabled', 'migrate:fresh', 'db:wipe'] as $needle) {
            $this->assertStringNotContainsString($needle, file_get_contents(app_path('Services/Sandbox/SandboxTemplateBuilder.php')));
        }
    }
}
