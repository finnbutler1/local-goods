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

    /** The one file the scanner skips (its own fixtures live here). */
    private const EXEMPT = 'tests/Unit/SecretScanScriptTest.php';

    /**
     * A secret setting's name, put together at run time: no line in this file
     * is ever a literal NAME=value, so nothing here looks like a committed
     * password even without the scanner's exemption.
     */
    private static function settingName(string $suffix): string
    {
        return match ($suffix) {
            'db' => 'DB_'.'PASSWORD',
            'stripe' => 'STRIPE_'.'SECRET',
            'app' => 'APP_'.'KEY',
            default => 'DEMO_'.'PASSWORD_'.$suffix,
        };
    }

    /** "NAME=value" for a fixture line. */
    private static function line(string $suffix, string $value): string
    {
        return self::settingName($suffix).'='.$value;
    }

    /** What the scanner prints for a hit on that setting. */
    private static function masked(string $suffix): string
    {
        return 'value masked: '.self::settingName($suffix).'='.str_repeat('*', 4);
    }

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
        file_put_contents($this->repo.'/settings.env', self::line('stripe', $key)."\n".self::line('db', $password)."\n");
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
        file_put_contents($this->repo.'/.env.example', self::line('app', '')."\n".self::line('stripe', '')."\n".self::line('db', 'null')."\n");
        $this->commit($this->repo, 'placeholders');

        [$code, $output] = $this->scan($this->repo);

        $this->assertSame(0, $code, $output);
    }

    public function test_the_readme_demo_password_placeholder_is_not_reported(): void
    {
        $placeholder = 'choose-a-'.'password';
        file_put_contents($this->repo.'/README.md', self::line('BID_ADMIN', $placeholder)."\n".self::line('EMPLOYEE', '"'.$placeholder.'"')."\n");
        $this->commit($this->repo, 'run it yourself');

        [$code, $output] = $this->scan($this->repo);

        $this->assertSame(0, $code, $output);
    }

    public function test_only_that_exact_value_on_a_demo_password_line_is_allowed(): void
    {
        // A realistic password, built at run time so this file never holds one.
        $real = 'Kestrel-'.bin2hex(random_bytes(5)).'!9';
        $placeholder = 'choose-a-'.'password';
        file_put_contents($this->repo.'/README.md', implode("\n", [
            self::line('BID_ADMIN', $real),
            self::line('MERCHANT', $placeholder.'2'),
            self::line('db', $placeholder),
        ])."\n");
        $this->commit($this->repo, 'not placeholders');

        [$code, $output] = $this->scan($this->repo);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('3 possible secret(s)', $output);
        $this->assertStringContainsString(self::masked('BID_ADMIN'), $output);
        $this->assertStringContainsString(self::masked('MERCHANT'), $output);
        $this->assertStringContainsString(self::masked('db'), $output);
        $this->assertStringNotContainsString($real, $output);
    }

    public function test_only_this_test_file_is_skipped_and_every_other_file_is_still_scanned(): void
    {
        $real = 'Kestrel-'.bin2hex(random_bytes(5)).'!9';
        $others = [
            'tests/Unit/OtherScriptTest.php',
            'tests/Feature/SecretScanScriptTest.php',
            'SecretScanScriptTest.php',
            self::EXEMPT.'.bak',
            'tests/Unit/SecretScanScriptTestHelper.php',
        ];
        foreach ([self::EXEMPT, ...$others] as $path) {
            @mkdir(dirname($this->repo.'/'.$path), 0777, true);
            file_put_contents($this->repo.'/'.$path, self::line('db', $real)."\n");
        }
        $this->commit($this->repo, 'same password-like line in several files');

        [$code, $output] = $this->scan($this->repo);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString(count($others).' possible secret(s)', $output);
        foreach ($others as $path) {
            $this->assertStringContainsString("file {$path} (".self::masked('db').')', $output);
        }
        $this->assertStringNotContainsString('file '.self::EXEMPT.' (', $output);
        $this->assertStringNotContainsString($real, $output);
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
