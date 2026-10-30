<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Rolling back one migration on its own breaks on MySQL once a later
 * migration points a foreign key at its tables (it broke CI when
 * demo_activity_actions began referencing corrections). Tests roll back
 * through Tests\Concerns\RollsBackMigrations, which unwinds every later
 * migration first, so a new table referencing an old one never needs a
 * test change.
 */
class MigrationSafetyTestsTest extends TestCase
{
    /** The one direct rollback: its down() refuses before dropping anything. */
    private const ALLOWED = ['Feature/LedgerMigrationSafetyTest.php' => 1];

    public function test_no_test_rolls_back_a_single_migration_by_itself(): void
    {
        $root = dirname(__DIR__);
        $found = [];

        foreach ((new Finder)->files()->in($root)->name('*.php')->notPath('Concerns/RollsBackMigrations.php') as $file) {
            $count = preg_match_all("/artisan\\(\\s*'migrate:(rollback|reset|refresh)'|->rollback\\(/", $file->getContents());
            if ($count > (self::ALLOWED[$file->getRelativePathname()] ?? 0)) {
                $found[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $found, 'Use rollBackSince() from Tests\Concerns\RollsBackMigrations');
    }

    public function test_every_migration_safety_test_uses_the_helper(): void
    {
        $files = glob(dirname(__DIR__).'/Feature/*MigrationSafetyTest.php');
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $this->assertStringContainsString('use RollsBackMigrations;', file_get_contents($file), basename($file));
        }
    }
}
