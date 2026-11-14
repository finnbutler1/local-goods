<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The two scripts that keep sandboxes going on the instance (ADR 0045):
 * run-scheduler.sh now also starts the scheduler when SANDBOX_ENABLED is
 * true (hourly cleanup, template build), and build-sandbox-template.sh,
 * called by the postdeploy hook, which must never fail a deploy. Both run
 * here with a fake get-config and a fake user switcher, so nothing real starts.
 */
class SandboxScheduleScriptsTest extends TestCase
{
    private const SCHEDULER = 'scripts/run-scheduler.sh';

    private const BUILDER = 'scripts/build-sandbox-template.sh';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/lg-sandbox-scripts-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->tmp.'/fake');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_the_scheduler_starts_when_either_switch_is_on_and_not_otherwise(): void
    {
        foreach ([
            '{}' => false,
            '{"SANDBOX_ENABLED":"false","DEMO_ACTIVITY_ENABLED":"false"}' => false,
            '{"SANDBOX_ENABLED":"1"}' => false,
            '{"SANDBOX_ENABLED":"true"}' => true,
            '{"SANDBOX_ENABLED":"True","DEMO_ACTIVITY_ENABLED":"false"}' => true,
            '{"DEMO_ACTIVITY_ENABLED":"true"}' => true,
        ] as $properties => $runs) {
            File::delete($this->tmp.'/ran');
            $process = $this->runScript(self::SCHEDULER, $properties);

            $this->assertSame(0, $process->getExitCode(), $properties);
            $this->assertSame($runs, File::exists($this->tmp.'/ran'), $properties);
            if ($runs) {
                $this->assertSame('runuser -u webapp -m -- php artisan schedule:run --no-interaction', trim(File::get($this->tmp.'/ran')));
            }
        }
    }

    public function test_the_builder_starts_nothing_unless_sandboxes_are_on(): void
    {
        foreach (['{}' => false, '{"SANDBOX_ENABLED":"false"}' => false, '{"DEMO_ACTIVITY_ENABLED":"true"}' => false, '{"SANDBOX_ENABLED":"true"}' => true] as $properties => $runs) {
            File::delete($this->tmp.'/ran');
            $process = $this->runScript(self::BUILDER, $properties);

            $this->assertSame(0, $process->getExitCode(), $properties);
            $this->assertSame($runs, File::exists($this->tmp.'/ran'), $properties);
            if ($runs) {
                $this->assertSame('runuser -u webapp -m -- php artisan sandbox:build-template --no-interaction', trim(File::get($this->tmp.'/ran')));
            }
        }
    }

    public function test_the_builder_never_fails_a_deploy(): void
    {
        // The build itself fails.
        $this->fake('runuser', 3);
        $failed = $this->runScript(self::BUILDER, '{"SANDBOX_ENABLED":"true"}');
        $this->assertSame(0, $failed->getExitCode());
        $this->assertStringContainsString('FAILED: sandbox:build-template exited 3', $failed->getErrorOutput());

        // No user switcher at all.
        $none = $this->runScript(self::BUILDER, '{"SANDBOX_ENABLED":"true"}', ['RUNUSER_PATHS' => $this->tmp.'/missing/runuser']);
        $this->assertSame(0, $none->getExitCode());
        $this->assertStringContainsString('FAILED: cannot switch to the webapp user', $none->getErrorOutput());

        // Properties unreadable, app folder missing.
        $this->assertSame(0, $this->run_(self::BUILDER, ['APP_DIR' => $this->tmp, 'GET_CONFIG' => $this->tmp.'/no-such'])->getExitCode());
        $this->assertSame(0, $this->run_(self::BUILDER, ['APP_DIR' => $this->tmp.'/missing'])->getExitCode());

        $script = File::get(base_path(self::BUILDER));
        $this->assertTrue(is_executable(base_path(self::BUILDER)));
        $this->assertDoesNotMatchRegularExpression('/^\s*set\s+-[a-zA-Z]*e/m', $script);
        $this->assertSame('exit 0', collect(explode("\n", trim($script)))->last());
        foreach (['migrate', 'db:wipe', 'db:seed', 'reset'] as $never) {
            $this->assertStringNotContainsString($never, $script);
        }
    }

    public function test_neither_script_switches_user_with_a_bare_command_name(): void
    {
        foreach ([self::SCHEDULER, self::BUILDER] as $path) {
            $code = collect(explode("\n", File::get(base_path($path))))
                ->reject(fn ($line) => str_starts_with(ltrim($line), '#') || str_starts_with(ltrim($line), 'log "'))->implode("\n");
            $this->assertDoesNotMatchRegularExpression('/(^|[\s;&|(`])(runuser|su|sudo|setpriv|chpst|doas)\s/m', $code, $path);
        }
    }

    private function fake(string $name, int $exit = 0): void
    {
        $path = $this->tmp.'/fake/'.$name;
        File::put($path, "#!/usr/bin/env bash\necho \"{$name} \$*\" > '".$this->tmp."/ran'\nexit {$exit}\n");
        chmod($path, 0755);
    }

    private function runScript(string $script, string $properties, array $env = []): Process
    {
        File::put($this->tmp.'/get-config', "#!/usr/bin/env bash\necho '".$properties."'\n");
        chmod($this->tmp.'/get-config', 0755);
        if (! File::exists($this->tmp.'/fake/runuser')) {
            $this->fake('runuser');
        }

        return $this->run_($script, $env + [
            'APP_DIR' => $this->tmp, 'GET_CONFIG' => $this->tmp.'/get-config',
            'RUNUSER_PATHS' => $this->tmp.'/fake/runuser', 'SU_PATHS' => $this->tmp.'/missing/su', 'SUDO_PATHS' => $this->tmp.'/missing/sudo',
        ]);
    }

    private function run_(string $script, array $env): Process
    {
        $process = new Process(['/bin/bash', base_path($script)], null, $env + ['PATH' => getenv('PATH')]);
        $process->run();

        return $process;
    }
}
