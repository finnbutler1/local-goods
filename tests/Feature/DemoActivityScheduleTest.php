<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * How the demo activity generator is scheduled on the live site (ADR 0032):
 * Laravel's scheduler, run every minute by cron on the instance. The hook
 * that installs the cron line must never fail a deploy, and the wrapper
 * cron calls must start nothing unless DEMO_ACTIVITY_ENABLED is true.
 */
class DemoActivityScheduleTest extends TestCase
{
    private const HOOK = '.platform/hooks/predeploy/02_scheduler.sh';

    private const CONFIG_HOOK = '.platform/confighooks/predeploy/02_scheduler.sh';

    private const WRAPPER = 'scripts/run-scheduler.sh';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/lg-schedule-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->tmp.'/bin');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_the_tick_is_scheduled_every_five_minutes_on_weekdays_in_dc_time_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'demo-activity:tick'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('*/5 * * * 1-5', $event->expression);
        $this->assertSame('America/New_York', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        // Only between 07:00 and 19:00 DC time. Laravel reads the clock when the
        // schedule is defined (each cron run starts fresh), so rebuild it each time.
        foreach (['2026-10-26 06:55' => false, '2026-10-26 07:00' => true, '2026-10-26 12:30' => true, '2026-10-26 19:05' => false] as $at => $runs) {
            \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse($at, 'America/New_York'));
            $this->refreshApplication();
            $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $tick = collect($this->app->make(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'demo-activity:tick'));
            $this->assertSame($runs, $tick->filtersPass($this->app), $at);
        }
    }

    public function test_the_hooks_never_fail_a_deploy_and_never_run_artisan(): void
    {
        foreach ([self::HOOK, self::CONFIG_HOOK] as $path) {
            $script = File::get(base_path($path));
            $this->assertTrue(is_executable(base_path($path)), "$path must be executable");
            $this->assertDoesNotMatchRegularExpression('/^\s*set\s+-[a-zA-Z]*e/m', $script, "$path must not stop on errors");
            $this->assertStringNotContainsString('artisan', $script);
            $this->assertSame('exit 0', collect(explode("\n", trim($script)))->last(), "$path must end with exit 0");
        }
        // Still before PHP restarts, like the database hook; no postdeploy hooks.
        $this->assertDirectoryDoesNotExist(base_path('.platform/hooks/postdeploy'));
    }

    public function test_the_hook_exits_0_even_when_it_cannot_write_the_cron_file(): void
    {
        $process = $this->run_(self::HOOK, ['CRON_FILE' => $this->tmp.'/missing-folder/local-goods-scheduler']);

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('The deploy continues', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->tmp.'/missing-folder/local-goods-scheduler');

        $config = $this->run_(self::CONFIG_HOOK, ['CRON_FILE' => $this->tmp.'/missing-folder/x']);
        $this->assertSame(0, $config->getExitCode());
    }

    public function test_the_hook_installs_one_cron_line_for_the_wrapper(): void
    {
        $cron = $this->tmp.'/local-goods-scheduler';
        $process = $this->run_(self::HOOK, ['CRON_FILE' => $cron, 'APP_DIR' => '/var/app/current']);

        $this->assertSame(0, $process->getExitCode());
        $lines = array_values(array_filter(explode("\n", File::get($cron)), fn ($l) => $l !== '' && ! str_starts_with($l, '#')));
        $this->assertSame(['* * * * * root /bin/bash /var/app/current/scripts/run-scheduler.sh >> /var/app/current/storage/logs/scheduler.log 2>&1'], $lines);
        $this->assertSame('0644', substr(sprintf('%o', fileperms($cron)), -4));
    }

    public function test_the_wrapper_starts_nothing_unless_switched_on(): void
    {
        foreach (['{}' => false, '{"DEMO_ACTIVITY_ENABLED":"false"}' => false, '{"DEMO_ACTIVITY_ENABLED":"1"}' => false,
            '{"DEMO_ACTIVITY_ENABLED":"true"}' => true, '{"DEMO_ACTIVITY_ENABLED":"True"}' => true] as $properties => $runs) {
            File::delete($this->tmp.'/ran');
            $process = $this->runWrapper($properties);

            $this->assertSame(0, $process->getExitCode(), $properties);
            $this->assertSame($runs, File::exists($this->tmp.'/ran'), $properties);
            if ($runs) {
                $this->assertSame('-u webapp -m -- php artisan schedule:run --no-interaction', trim(File::get($this->tmp.'/ran')));
            }
        }
    }

    public function test_the_wrapper_exits_0_when_it_cannot_read_the_properties(): void
    {
        $process = $this->run_(self::WRAPPER, ['APP_DIR' => $this->tmp, 'GET_CONFIG' => $this->tmp.'/no-such-get-config', 'PATH' => $this->tmp.'/bin:'.getenv('PATH')]);

        $this->assertSame(0, $process->getExitCode());
        $this->assertFileDoesNotExist($this->tmp.'/ran');
    }

    /** Runs the wrapper with fake get-config and runuser, so nothing real starts. */
    private function runWrapper(string $properties): Process
    {
        File::put($this->tmp.'/get-config', "#!/usr/bin/env bash\necho '".$properties."'\n");
        File::put($this->tmp.'/bin/runuser', "#!/usr/bin/env bash\necho \"\$*\" > '".$this->tmp."/ran'\n");
        chmod($this->tmp.'/get-config', 0755);
        chmod($this->tmp.'/bin/runuser', 0755);

        return $this->run_(self::WRAPPER, ['APP_DIR' => $this->tmp, 'GET_CONFIG' => $this->tmp.'/get-config', 'PATH' => $this->tmp.'/bin:'.getenv('PATH')]);
    }

    private function run_(string $script, array $env): Process
    {
        $process = new Process(['bash', base_path($script)], base_path(), $env + ['PATH' => getenv('PATH')]);
        $process->run();

        return $process;
    }
}
