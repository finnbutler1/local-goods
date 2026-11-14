<?php

namespace App\Services\Sandbox;

use App\Services\Ai\ClaudeClient;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use LogicException;
use PDO;

/**
 * Turns one web request into a sandbox request and back (ADR 0045).
 *
 * Activating points the default database connection at the sandbox's own
 * SQLite file, so every app model reads and writes there. Everything shared
 * is pinned to what it was before: the session and the main cache's database
 * store keep the main connection by name. The sandbox gets its own cache
 * (a database store inside its file: throttles, the solvency baseline, the AI
 * count), its own login guard (SandboxUserProvider), no Stripe keys, and a
 * Claude client with a call cap. Deactivating puts every one of these back.
 *
 * Nothing outside UseSandbox (web requests with a valid sandbox cookie)
 * activates a sandbox: console commands, the scheduler and the Stripe webhook
 * never do.
 */
class Sandbox
{
    public const CONNECTION = 'sandbox';

    public const GUARD = 'sandbox';

    public const CACHE_STORE = 'sandbox';

    /** The cookie that names this browser's sandbox (encrypted and signed by Laravel). */
    public const COOKIE = 'lg_sandbox';

    /** The sandbox's own session cookie, so the live login's session is never read or written. */
    public const SESSION_COOKIE = 'lg_sandbox_session';

    private ?string $id = null;

    /** A sandbox to delete once this request is done with it (Exit). */
    private ?string $endAfterRequest = null;

    /** @var array<string, mixed> */
    private array $saved = [];

    public function __construct(private SandboxFiles $files) {}

    /** On, and PHP can open SQLite files. Off: no button, every /sandbox address is a 404. */
    public static function enabled(): bool
    {
        return config('sandbox.enabled') === true && in_array('sqlite', PDO::getAvailableDrivers(), true);
    }

    /** True only while this request runs inside a sandbox. */
    public static function active(): bool
    {
        return app()->resolved(self::class) && app(self::class)->id !== null;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    /** The cache store that was the default before the sandbox (creation limits live there). */
    public function mainCacheStore(): string
    {
        return $this->saved['cache.default'] ?? (string) config('cache.default');
    }

    /** The live database connection's name, whether or not a sandbox is active. */
    public function mainConnection(): string
    {
        return $this->saved['database.default'] ?? (string) config('database.default');
    }

    public function activate(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('A sandbox is already active in this request.');
        }
        if (! $this->files->isLive($id)) {
            throw new LogicException('That sandbox has ended.');
        }

        $main = (string) config('database.default');
        $this->saved = [
            'database.default' => $main,
            'cache.default' => config('cache.default'),
            'auth.defaults.guard' => config('auth.defaults.guard'),
            'session.connection' => config('session.connection'),
            'cache.stores.database.connection' => config('cache.stores.database.connection'),
            'cache.stores.database.lock_connection' => config('cache.stores.database.lock_connection'),
            'services.stripe' => config('services.stripe'),
            'claude' => app(ClaudeClient::class),
            'limiter' => app(RateLimiter::class),
        ];

        config([
            'database.connections.'.self::CONNECTION => self::connectionConfig($this->files->databasePath($id)),
            // Shared things keep the live connection by name.
            'session.connection' => $this->saved['session.connection'] ?? $main,
            'cache.stores.database.connection' => $this->saved['cache.stores.database.connection'] ?? $main,
            'cache.stores.database.lock_connection' => $this->saved['cache.stores.database.lock_connection'] ?? $main,
            'cache.stores.'.self::CACHE_STORE => [
                'driver' => 'database', 'connection' => self::CONNECTION, 'table' => 'cache',
                'lock_connection' => self::CONNECTION, 'lock_table' => 'cache_locks',
            ],
            // No Stripe keys: StripeMode is simulated, so every Stripe caller takes its simulated path.
            'services.stripe.key' => null,
            'services.stripe.secret' => null,
            'services.stripe.webhook_secret' => null,
        ]);

        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);
        Cache::setDefaultDriver(self::CACHE_STORE);
        $this->useLimiter(self::limiterOn($this->saved['limiter'], Cache::store(self::CACHE_STORE)));
        Auth::shouldUse(self::GUARD);
        app()->instance(ClaudeClient::class, new CappedClaudeClient($this->saved['claude'], new SandboxAiBudget($this->files)));

        $this->id = $id;
    }

    /** Exit: the request still needs the file (throttle counts, the session), so it goes afterwards. */
    public function endAfterRequest(): void
    {
        $this->endAfterRequest = $this->id;
    }

    /** Deactivates, then deletes the sandbox if Exit asked for that. Called by UseSandbox when the request is done. */
    public function finishRequest(): void
    {
        $this->deactivate();
        if ($this->endAfterRequest !== null) {
            $this->files->destroy($this->endAfterRequest);
            $this->endAfterRequest = null;
        }
    }

    public function deactivate(): void
    {
        if ($this->id === null) {
            return;
        }

        Auth::guard(self::GUARD)->forgetUser();
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->saved['database.default']);
        Cache::forgetDriver(self::CACHE_STORE);
        config([
            'database.default' => $this->saved['database.default'],
            'cache.default' => $this->saved['cache.default'],
            'session.connection' => $this->saved['session.connection'],
            'cache.stores.database.connection' => $this->saved['cache.stores.database.connection'],
            'cache.stores.database.lock_connection' => $this->saved['cache.stores.database.lock_connection'],
            'services.stripe' => $this->saved['services.stripe'],
        ]);
        config()->offsetUnset('database.connections.'.self::CONNECTION);
        config()->offsetUnset('cache.stores.'.self::CACHE_STORE);
        Cache::setDefaultDriver($this->saved['cache.default']);
        Auth::shouldUse($this->saved['auth.defaults.guard']);
        $this->useLimiter($this->saved['limiter']);
        app()->instance(ClaudeClient::class, $this->saved['claude']);

        $this->id = null;
        $this->saved = [];
    }

    /** @return array<string, mixed> */
    public static function connectionConfig(string $path): array
    {
        return [
            'driver' => 'sqlite',
            'url' => null,
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            // One file per sandbox, nothing beside it: copying and deleting stay simple.
            'journal_mode' => 'delete',
            'synchronous' => 'normal',
            'transaction_mode' => 'IMMEDIATE',
        ];
    }

    /** The same named limiters (redeem, feedback...), counted in another cache store. */
    private static function limiterOn(RateLimiter $from, Repository $store): RateLimiter
    {
        $limiter = new RateLimiter($store);
        $named = (fn () => $this->limiters)->call($from);
        foreach ($named as $name => $callback) {
            $limiter->for($name, $callback);
        }

        return $limiter;
    }

    private function useLimiter(RateLimiter $limiter): void
    {
        app()->instance(RateLimiter::class, $limiter);
        RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    }
}
