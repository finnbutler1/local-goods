<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the Beanstalk hooks: a deploy or configuration change must never
 * wipe the production database.
 */
class DeployHookTest extends TestCase
{
    public static function hooks(): array
    {
        return [
            'deploy hook' => ['.platform/hooks/postdeploy/01_database.sh'],
            'config hook' => ['.platform/confighooks/postdeploy/01_database.sh'],
        ];
    }

    #[DataProvider('hooks')]
    public function test_hook_never_wipes_the_database(string $path): void
    {
        $script = $this->code($path);

        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe', '--seed', 'db:seed'] as $danger) {
            $this->assertStringNotContainsString($danger, $script, "$path must not run $danger");
        }
        $this->assertTrue(is_executable(dirname(__DIR__, 2).'/'.$path), "$path must be executable");
    }

    public function test_deploy_hook_migrates_safely_and_seeds_only_when_empty(): void
    {
        $script = $this->code('.platform/hooks/postdeploy/01_database.sh');

        $this->assertStringContainsString('php artisan migrate --force', $script);
        $this->assertStringContainsString('php artisan demo:seed-if-empty', $script);
        $this->assertStringContainsString('get-config environment', $script);
    }

    /** The script without comment lines, so comments may mention what it avoids. */
    private function code(string $path): string
    {
        $lines = file(dirname(__DIR__, 2).'/'.$path);

        return implode('', array_filter($lines, fn ($line) => ! str_starts_with(ltrim($line), '#')));
    }
}
