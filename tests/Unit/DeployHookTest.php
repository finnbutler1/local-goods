<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Guards the Beanstalk hooks: a deploy or configuration change must never
 * wipe the production database, and a bad setting must stop the deployment
 * before PHP restarts, so the running site is left alone.
 */
class DeployHookTest extends TestCase
{
    private const HOOK = '.platform/hooks/predeploy/01_database.sh';

    private const CONFIG_HOOK = '.platform/confighooks/predeploy/01_database.sh';

    public static function hooks(): array
    {
        return [
            'deploy hook' => [self::HOOK],
            'config hook' => [self::CONFIG_HOOK],
        ];
    }

    #[DataProvider('hooks')]
    public function test_hook_never_wipes_or_caches(string $path): void
    {
        $script = $this->code($path);

        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe', '--seed', 'db:seed', 'config:cache', 'optimize'] as $danger) {
            $this->assertStringNotContainsString($danger, $script, "$path must not run $danger");
        }
        $this->assertTrue(is_executable($this->root($path)), "$path must be executable");
    }

    public function test_hooks_run_before_php_restarts(): void
    {
        $this->assertDirectoryDoesNotExist($this->root('.platform/hooks/postdeploy'));
        $this->assertDirectoryDoesNotExist($this->root('.platform/confighooks/postdeploy'));
        $this->assertStringNotContainsString('/var/app/current', $this->code(self::HOOK).$this->code(self::CONFIG_HOOK));
    }

    public function test_deploy_hook_migrates_safely_and_seeds_only_when_empty(): void
    {
        $script = $this->code(self::HOOK);

        $this->assertStringContainsString('php artisan migrate --force', $script);
        $this->assertStringContainsString('php artisan demo:seed-accounts', $script);
        $this->assertStringContainsString('php artisan demo:seed-if-empty', $script);
        $this->assertLessThan(strpos($script, 'demo:seed-accounts'), strpos($script, 'migrate --force'), 'migrate before creating logins');
        $this->assertStringContainsString('trap on_error ERR', $script);
        $this->assertStringContainsString('config:clear', $script);
    }

    /**
     * Checked by reading the hook, not running it: a passing check would go
     * on to migrate whatever database the environment points at.
     */
    public function test_deploy_hook_checks_production_settings_before_touching_anything(): void
    {
        $script = $this->code(self::HOOK);
        $check = strpos($script, 'php artisan app:check-production-config');

        $this->assertNotFalse($check, 'The hook must run app:check-production-config.');
        $this->assertStringContainsString('set -Eeuo pipefail', $script);
        foreach (['mkdir -p storage', 'touch storage/database.sqlite', 'migrate --force', 'demo:seed-accounts'] as $later) {
            $this->assertLessThan(strpos($script, $later), $check, "Check settings before: {$later}");
        }
        $this->assertStringNotContainsString('app:check-production-config ||', $script, 'A failed check must stop the deploy.');
    }

    public static function badSettings(): array
    {
        return [
            'typo in connection name' => [['DB_CONNECTION' => 'mysq1'], "DB_CONNECTION must be exactly 'mysql' or 'sqlite'"],
            'missing host' => [['DB_CONNECTION' => 'mysql', 'DB_PORT' => '3306', 'DB_DATABASE' => 'local_goods', 'DB_USERNAME' => 'app', 'DB_PASSWORD' => 'not-a-real-secret'], 'DB_HOST is missing'],
            'empty password' => [['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'db.example.test', 'DB_PORT' => '3306', 'DB_DATABASE' => 'local_goods', 'DB_USERNAME' => 'app', 'DB_PASSWORD' => ''], 'DB_PASSWORD is missing'],
        ];
    }

    #[DataProvider('badSettings')]
    public function test_bad_settings_stop_the_deploy_before_migrating_without_printing_values(array $env, string $message): void
    {
        foreach ([self::HOOK, self::CONFIG_HOOK] as $hook) {
            $process = $this->runHook($hook, $env);

            $this->assertSame(1, $process->getExitCode());
            $output = $process->getOutput().$process->getErrorOutput();
            $this->assertStringContainsString($message, $output);
            $this->assertStringContainsString('the running site is unchanged', $output);
            $this->assertStringNotContainsString('Migrat', $output);
            foreach (['not-a-real-secret', 'db.example.test'] as $value) {
                $this->assertStringNotContainsString($value, $output);
            }
        }
    }

    /** Runs a hook with a stand-in for Beanstalk's get-config that returns $env. */
    private function runHook(string $hook, array $env): Process
    {
        $dir = sys_get_temp_dir().'/lg-hook-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/environment.json", json_encode($env));
        file_put_contents("$dir/get-config", "#!/usr/bin/env bash\ncat ".escapeshellarg("$dir/environment.json")."\n");
        chmod("$dir/get-config", 0755);

        // Remove DB_ settings inherited from the test run (the MySQL CI job sets
        // them), so the hook only sees what get-config returns.
        $inherited = array_fill_keys(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL'], false);
        $process = new Process(['bash', $this->root($hook)], null, ['GET_CONFIG' => "$dir/get-config"] + $inherited);
        $process->run();

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);

        return $process;
    }

    private function root(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }

    /** The script without comment lines, so comments may mention what it avoids. */
    private function code(string $path): string
    {
        $lines = file($this->root($path));

        return implode('', array_filter($lines, fn ($line) => ! str_starts_with(ltrim($line), '#')));
    }
}
