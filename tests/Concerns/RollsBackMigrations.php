<?php

namespace Tests\Concerns;

/**
 * Migration safety tests roll a migration back and run it again. Rolling
 * back only that one fails on MySQL as soon as a later migration adds a
 * foreign key to one of its tables ("Cannot drop table ... referenced by
 * a foreign key"); SQLite lets it through while the referencing table is
 * empty. So this unwinds every later migration first, newest first, the
 * way a real rollback would, and migrateAgain() runs them all forward in
 * order. A future table that points at an older one is covered without
 * touching any test. MigrationSafetyTestsTest checks every safety test
 * uses this rather than rolling back a single path.
 */
trait RollsBackMigrations
{
    /** Rolls back $migration and every migration after it, newest first. */
    protected function rollBackSince(string $migration): void
    {
        $all = collect(glob(database_path('migrations/*.php')))->map(fn (string $path) => 'database/migrations/'.basename($path))->sort()->values();
        $this->assertContains($migration, $all, "{$migration} is not a migration");

        foreach ($all->filter(fn (string $path) => strcmp($path, $migration) >= 0)->reverse() as $path) {
            $this->artisan('migrate:rollback', ['--path' => $path])->assertSuccessful();
        }
    }

    /** Runs every migration that is not applied, oldest first. */
    protected function migrateAgain(): void
    {
        $this->artisan('migrate')->assertSuccessful();
    }
}
