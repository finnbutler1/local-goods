<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests run on an in-memory database, so these use a throwaway SQLite file
 * to check the real-file settings used locally and on Elastic Beanstalk.
 */
class SqliteConcurrencyTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'lg-sqlite-');
        $file = array_merge(config('database.connections.sqlite'), [
            'database' => $this->path,
            'busy_timeout' => 5000,
            'journal_mode' => 'wal',
            'synchronous' => 'normal',
            'transaction_mode' => 'IMMEDIATE',
        ]);

        config([
            'database.connections.file_a' => $file,
            // A second writer that gives up immediately, to detect the first one's lock.
            'database.connections.file_b' => array_merge($file, ['busy_timeout' => 0]),
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('file_a');
        DB::purge('file_b');
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path.$suffix);
        }
        parent::tearDown();
    }

    public function test_app_config_enables_wal_busy_timeout_and_immediate_transactions(): void
    {
        $sqlite = config('database.connections.sqlite');

        $this->assertSame('wal', $sqlite['journal_mode']);
        $this->assertSame(5000, $sqlite['busy_timeout']);
        $this->assertSame('IMMEDIATE', $sqlite['transaction_mode']);
    }

    public function test_settings_are_applied_to_a_real_database_file(): void
    {
        $db = DB::connection('file_a');

        $this->assertSame('wal', $db->selectOne('pragma journal_mode')->journal_mode);
        $this->assertSame(5000, (int) $db->selectOne('pragma busy_timeout')->timeout);
    }

    public function test_transactions_take_the_write_lock_immediately(): void
    {
        if (version_compare(PHP_VERSION, '8.4.0', '<')) {
            $this->markTestSkipped('Laravel only honors transaction_mode on PHP 8.4+.');
        }

        $a = DB::connection('file_a');
        $b = DB::connection('file_b');
        $a->statement('create table t (id integer primary key)');

        // A opens a transaction but has not written anything yet.
        $a->beginTransaction();

        try {
            $b->insert('insert into t default values');
            $this->fail('Second writer should be blocked by the IMMEDIATE transaction.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('database is locked', $e->getMessage());
        } finally {
            $a->rollBack();
        }

        // Once A is done, B can write.
        $this->assertTrue($b->insert('insert into t default values'));
    }
}
