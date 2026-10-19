<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * scripts/scan-history-for-secrets.sh checks every line ever committed for
 * keys and passwords. It must never print what it found, and it must refuse
 * a shallow clone instead of passing on part of the history. Most tests run
 * it in a throwaway git repository; the last one scans this repository.
 */
class SecretScanScriptTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../scripts/scan-history-for-secrets.sh';

    private string $repo;

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir().'/secret-scan-'.bin2hex(random_bytes(4));
        mkdir($this->repo, 0777, true);
        $this->git($this->repo, 'init -q');
        file_put_contents($this->repo.'/README.md', 'nothing secret');
        $this->commit($this->repo, 'first');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->repo).' '.escapeshellarg($this->repo.'-shallow'));
    }

    private function git(string $dir, string $args): string
    {
        exec('cd '.escapeshellarg($dir).' && git -c user.email=t@example.test -c user.name=Test '.$args.' 2>&1', $out);

        return implode("\n", $out);
    }

    private function commit(string $dir, string $message): void
    {
        $this->git($dir, 'add -A');
        $this->git($dir, 'commit -q -m '.escapeshellarg($message));
    }

    /** @return array{0: int, 1: string} exit code and everything printed */
    private function scan(string $dir): array
    {
        exec('cd '.escapeshellarg($dir).' && '.escapeshellarg(realpath(self::SCRIPT)).' 2>&1', $out, $code);

        return [$code, implode("\n", $out)];
    }

    public function test_a_clean_history_passes(): void
    {
        [$code, $output] = $this->scan($this->repo);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('full history', $output);
    }

    public function test_a_committed_key_is_reported_without_printing_it(): void
    {
        // Built at run time so this file never contains a key-shaped string.
        $key = 'sk_'.'live_'.bin2hex(random_bytes(12));
        $password = 'pw'.bin2hex(random_bytes(8));
        file_put_contents($this->repo.'/settings.env', "STRIPE_SECRET={$key}\nDB_PASSWORD={$password}\n");
        $this->commit($this->repo, 'oops');
        // Deleting it later doesn't hide it: it is still in the history.
        unlink($this->repo.'/settings.env');
        $this->commit($this->repo, 'remove it');
        $commit = substr($this->git($this->repo, 'rev-parse HEAD~1'), 0, 12);

        [$code, $output] = $this->scan($this->repo);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString("Found stripe_secret_key in commit {$commit}, file settings.env (value masked: sk_live_****)", $output);
        $this->assertStringContainsString('Found secret_setting', $output);
        $this->assertStringNotContainsString($key, $output);
        $this->assertStringNotContainsString(substr($key, 8, 10), $output);
        $this->assertStringNotContainsString($password, $output);
    }

    public function test_known_test_placeholders_are_not_reported(): void
    {
        file_put_contents($this->repo.'/FakeTest.php', "<?php\n\$secret = 'sk_test_SECRETVALUE';\n\$hook = 'whsec_test_local_goods_fake';\n");
        file_put_contents($this->repo.'/.env.example', "APP_KEY=\nSTRIPE_SECRET=\nDB_PASSWORD=null\n");
        $this->commit($this->repo, 'placeholders');

        [$code, $output] = $this->scan($this->repo);

        $this->assertSame(0, $code, $output);
    }

    public function test_a_shallow_clone_fails_loudly_instead_of_passing(): void
    {
        file_put_contents($this->repo.'/README.md', 'second');
        $this->commit($this->repo, 'second');
        exec('git clone -q --depth 1 '.escapeshellarg('file://'.$this->repo).' '.escapeshellarg($this->repo.'-shallow').' 2>&1');

        [$code, $output] = $this->scan($this->repo.'-shallow');

        $this->assertSame(2, $code, $output);
        $this->assertStringContainsString('Shallow history', $output);
        $this->assertStringNotContainsString('no secrets found', $output);
    }

    /** CI checks out the full history (fetch-depth: 0) so this sees every commit. */
    public function test_this_repository_has_no_secrets_in_its_history(): void
    {
        [$code, $output] = $this->scan(dirname(__DIR__, 2));

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('full history, all branches', $output);
    }
}
