<?php

namespace App\Services\Sandbox;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Builds today's sandbox template (ADR 0045): a brand-new SQLite file with
 * every migration and the same demo seed as a fresh install, plus one
 * sandbox login per role. Run by `sandbox:build-template` (postdeploy hook
 * and the scheduler), never during a web request.
 *
 * The default connection is pointed at the new file before anything runs,
 * and the cache at an in-memory store, so the build never queries the live
 * database or writes the live cache. It is written under a temporary name
 * and renamed when complete.
 */
class SandboxTemplateBuilder
{
    public const CONNECTION = 'sandbox_build';

    /** Fictional names for the sandbox logins (the live demo names come from settings). */
    private const USERS = [
        'employer' => 'Alex Morgan',
        'employee' => 'Jordan Rivera',
        'merchant' => 'Priya Natarajan',
        'bid_admin' => 'Sam Taylor',
        'reviewer' => 'Riley Chen',
    ];

    public function __construct(private SandboxFiles $files) {}

    /** @return array{path: string, built: bool, seconds: float, bytes: int} */
    public function build(bool $force = false): array
    {
        $target = $this->files->templatePath();
        if (! $force && is_file($target)) {
            return ['path' => $target, 'built' => false, 'seconds' => 0.0, 'bytes' => (int) filesize($target)];
        }

        $started = microtime(true);
        $tmp = $target.'.tmp-'.Str::random(8);
        touch($tmp);

        $mainConnection = DB::getDefaultConnection();
        $mainCache = config('cache.default');
        config(['database.connections.'.self::CONNECTION => Sandbox::connectionConfig($tmp)]);

        try {
            DB::setDefaultConnection(self::CONNECTION);
            Cache::setDefaultDriver('array');

            $output = new BufferedOutput;
            if (Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true], $output) !== 0) {
                throw new RuntimeException('Migrating the sandbox template failed: '.$output->fetch());
            }

            DB::transaction(function () {
                (new DatabaseSeeder)->run();
                $this->addSandboxLogins();
            });
        } catch (Throwable $e) {
            @unlink($tmp);
            throw $e;
        } finally {
            DB::purge(self::CONNECTION);
            DB::setDefaultConnection($mainConnection);
            Cache::setDefaultDriver($mainCache);
            config()->offsetUnset('database.connections.'.self::CONNECTION);
        }

        @chmod($tmp, 0660);
        if (! @rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('The sandbox template could not be saved.');
        }

        return ['path' => $target, 'built' => true, 'seconds' => round(microtime(true) - $started, 2), 'bytes' => (int) filesize($target)];
    }

    /**
     * One login per role. No password anyone knows (not even a valid hash),
     * and the sandbox guard refuses passwords anyway. The employee runs the
     * first seeded wallet and the merchant runs the demo merchant, as on live.
     */
    private function addSandboxLogins(): void
    {
        $now = now();
        $ids = [];
        foreach (SandboxRoutes::ROLES as $key => [, $role]) {
            $ids[$key] = DB::table('users')->insertGetId([
                'name' => self::USERS[$key],
                'email' => SandboxRoutes::email($key),
                'password' => '!sandbox-login-has-no-password',
                'role' => $role->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Employee::orderBy('id')->firstOrFail()->update(['user_id' => $ids['employee']]);
        Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail()->update(['user_id' => $ids['merchant']]);

        if (DB::table('users')->where('role', Role::Judge->value)->exists()) {
            throw new RuntimeException('A sandbox template must not contain reviewer accounts.');
        }
    }
}
