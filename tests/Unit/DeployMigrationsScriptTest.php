<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * scripts/deploy-migrations.sh tells the deploy build whether any migration
 * changed since the last deployed commit, so an RDS snapshot is taken first.
 * Each test runs the real scripts in a throwaway git repository.
 */
class DeployMigrationsScriptTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir().'/deploy-migrations-'.bin2hex(random_bytes(4));
        mkdir($this->repo.'/scripts', 0777, true);
        mkdir($this->repo.'/database/migrations', 0777, true);
        foreach (['deploy-migrations.sh', 'mark-deployed.sh'] as $script) {
            copy(__DIR__.'/../../scripts/'.$script, $this->repo.'/scripts/'.$script);
            chmod($this->repo.'/scripts/'.$script, 0755);
        }

        $this->git('init -q');
        $this->write('database/migrations/2026_01_01_000001_first.php');
        $this->write('README.md');
        $this->commit('first');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->repo));
    }

    private function git(string $args): string
    {
        exec('cd '.escapeshellarg($this->repo).' && git -c user.email=t@example.test -c user.name=Test '.$args.' 2>&1', $out);

        return implode("\n", $out);
    }

    private function write(string $path, string $body = '<?php'): void
    {
        file_put_contents($this->repo.'/'.$path, $body);
    }

    private function commit(string $message): void
    {
        $this->git('add -A');
        $this->git('commit -q -m '.escapeshellarg($message));
    }

    /** @return array{0: int, 1: list<string>} exit code and listed files */
    private function check(): array
    {
        exec(escapeshellarg($this->repo.'/scripts/deploy-migrations.sh'), $out, $code);

        return [$code, $out];
    }

    private function markDeployed(?string $commit = null): void
    {
        exec(escapeshellarg($this->repo.'/scripts/mark-deployed.sh').($commit ? ' '.escapeshellarg($commit) : '').' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function test_no_record_yet_is_reported_as_unknown(): void
    {
        $this->assertSame(3, $this->check()[0]);
    }

    public function test_nothing_changed_lists_nothing(): void
    {
        $this->markDeployed($this->git('rev-parse HEAD'));
        $this->write('README.md', 'changed, not a migration');
        $this->commit('docs');

        $this->assertSame([0, []], $this->check());
    }

    public function test_new_changed_and_uncommitted_migrations_are_listed(): void
    {
        $this->markDeployed($this->git('rev-parse HEAD'));

        $this->write('database/migrations/2026_02_01_000001_second.php');
        $this->commit('second');
        $this->write('database/migrations/2026_01_01_000001_first.php', '<?php // edited');
        $this->write('database/migrations/2026_03_01_000001_not_committed_yet.php');

        $this->assertSame([0, [
            'database/migrations/2026_01_01_000001_first.php',
            'database/migrations/2026_02_01_000001_second.php',
            'database/migrations/2026_03_01_000001_not_committed_yet.php',
        ]], $this->check());
    }

    public function test_mark_deployed_records_the_commit_the_zip_was_built_from(): void
    {
        $built = $this->git('rev-parse HEAD');
        file_put_contents($this->repo.'/.deploy-zip-commit', $built."\n");

        // A commit made after building isn't what went live.
        $this->write('database/migrations/2026_02_01_000001_second.php');
        $this->commit('after the build');

        $this->markDeployed();

        $this->assertSame($built, trim(file_get_contents($this->repo.'/.last-deploy-commit')));
        $this->assertSame([0, ['database/migrations/2026_02_01_000001_second.php']], $this->check());
    }

    public function test_a_recorded_commit_not_in_the_repo_is_reported_as_unknown(): void
    {
        file_put_contents($this->repo.'/.last-deploy-commit', str_repeat('a', 40));

        $this->assertSame(3, $this->check()[0]);
    }

    public function test_the_build_script_only_reminds_and_never_fails_on_it(): void
    {
        $script = file_get_contents(__DIR__.'/../../scripts/build-deploy-zip.sh');
        $reminder = substr($script, strpos($script, 'scripts/deploy-migrations.sh') - 200);

        $this->assertStringContainsString('set +e', $reminder);
        $this->assertStringContainsString('This deploy includes database migrations:', $reminder);
        $this->assertStringContainsString('Take an RDS snapshot before uploading.', $reminder);
        $this->assertStringContainsString('No migrations in this deploy.', $reminder);
        $this->assertStringNotContainsString('fail ', $reminder, 'The reminder must never stop the build.');
        $this->assertStringNotContainsString('exit', $reminder);
    }
}
