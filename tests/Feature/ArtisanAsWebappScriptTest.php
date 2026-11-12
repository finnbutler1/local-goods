<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * scripts/artisan-as-webapp.sh runs a few approved commands by hand on the
 * Beanstalk instance, as webapp with the environment properties loaded. It
 * refuses anything else before PHP starts and never prints a property value.
 */
class ArtisanAsWebappScriptTest extends TestCase
{
    private const SCRIPT = 'scripts/artisan-as-webapp.sh';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/lg-artisan-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->tmp.'/fake');
        File::put($this->tmp.'/get-config', "#!/usr/bin/env bash\necho '{\"DB_PASSWORD\":\"not-a-real-password\"}'\n");
        chmod($this->tmp.'/get-config', 0755);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public static function allowed(): array
    {
        return [['demo:check-mail-guard'], ['demo:update-emails'], ['demo:update-emails --apply'],
            ['auth:disable-two-step staff.person@example.test'], ['auth:disable-two-step Staff_Person+2fa@mail.example.co.uk --apply']];
    }

    #[DataProvider('allowed')]
    public function test_it_runs_an_approved_command_as_webapp_with_the_properties(string $command): void
    {
        $process = $this->run_(explode(' ', $command));

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('runuser -u webapp -m -- php artisan '.$command.' --no-interaction|not-a-real-password', trim(File::get($this->tmp.'/ran')));
        $this->assertStringNotContainsString('not-a-real-password', $process->getOutput().$process->getErrorOutput());
    }

    public static function refused(): array
    {
        return [[[]], [['migrate:fresh']], [['db:wipe']], [['tinker']], [['demo:update-emails', '--force']],
            [['demo:check-mail-guard;', 'rm', '-rf', '/']], [['demo:update-emails', '--apply', '--env=local']],
            // auth:disable-two-step: exactly one plain email, then optionally --apply.
            [['auth:disable-two-step']], [['auth:disable-two-step', '--apply']],
            [['auth:disable-two-step', 'a@example.test;rm -rf /']], [['auth:disable-two-step', 'a@example.test rm']],
            [['auth:disable-two-step', 'a@example.test', '--env=local']], [['auth:disable-two-step', 'a@example.test', '--apply', '--env=local']],
            [['auth:disable-two-step', 'a@example.test', 'b@example.test']], [['auth:disable-two-step', '--env=local@example.test']],
            [['auth:disable-two-step', '$(id)@example.test']], [['auth:disable-two-step', 'a@*.test']], [['auth:disable-two-step', 'a@example']],
            [['auth:disable-two-step', "a@example.test\nb@example.test"]], [['auth:disable-two-step', '"a"@example.test']],
            [['auth:disable-two-step a@example.test']]];
    }

    #[DataProvider('refused')]
    public function test_it_refuses_anything_else_before_php_starts(array $args): void
    {
        $process = $this->run_($args);

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('Refused', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->tmp.'/ran');
    }

    public function test_it_fails_loudly_when_it_cannot_read_the_properties(): void
    {
        $process = $this->run_(['demo:check-mail-guard'], ['GET_CONFIG' => $this->tmp.'/missing']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('run it with sudo', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->tmp.'/ran');
    }

    public function test_it_passes_on_the_command_exit_code(): void
    {
        $process = $this->run_(['demo:check-mail-guard'], [], 3);

        $this->assertSame(3, $process->getExitCode());
    }

    private function run_(array $args, array $env = [], int $exit = 0): Process
    {
        // A fake runuser that records what it was asked to run (and that the property arrived) instead of switching user.
        File::put($this->tmp.'/fake/runuser', "#!/usr/bin/env bash\necho \"runuser \$*|\$DB_PASSWORD\" > '".$this->tmp."/ran'\nexit {$exit}\n");
        chmod($this->tmp.'/fake/runuser', 0755);

        $process = new Process(['bash', base_path(self::SCRIPT), ...$args], base_path(), $env + [
            'APP_DIR' => $this->tmp, 'GET_CONFIG' => $this->tmp.'/get-config', 'PATH' => getenv('PATH'),
            'RUNUSER_PATHS' => $this->tmp.'/fake/runuser', 'SU_PATHS' => $this->tmp.'/missing/su', 'SUDO_PATHS' => $this->tmp.'/missing/sudo',
        ]);
        $process->run();

        return $process;
    }
}
