<?php

namespace Tests\Concerns;

use App\Services\Sandbox\Sandbox;
use App\Services\Sandbox\SandboxFiles;
use App\Services\Sandbox\SandboxTemplateBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/**
 * Sandboxes switched on in a temporary folder, with today's template ready
 * (ADR 0045). The template is built once per test run and copied in, since
 * every test needs the same one. Requests made with sandboxGet/sandboxPost
 * carry the browser's two sandbox cookies, like a real browser would.
 */
trait UsesSandboxes
{
    private static ?string $builtTemplate = null;

    protected string $sandboxDir;

    /** @var array<string, string> the browser's sandbox cookies, decrypted */
    protected array $sandboxJar = [];

    protected function setUpSandboxes(bool $withTemplate = true): void
    {
        $this->sandboxDir = sys_get_temp_dir().'/lg-sandboxes-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($this->sandboxDir);
        config(['sandbox.enabled' => true, 'sandbox.path' => $this->sandboxDir]);
        $this->sandboxJar = [];

        if ($withTemplate) {
            copy(self::template(), app(SandboxFiles::class)->templatePath());
        }

        $this->beforeApplicationDestroyed(function () {
            if (Sandbox::active()) {
                app(Sandbox::class)->deactivate();
            }
            DB::purge(Sandbox::CONNECTION);
            File::deleteDirectory($this->sandboxDir);
        });
    }

    /** Built once per run, in its own folder, never on the test's database. */
    protected static function template(): string
    {
        if (self::$builtTemplate !== null && is_file(self::$builtTemplate)) {
            return self::$builtTemplate;
        }

        $dir = sys_get_temp_dir().'/lg-sandbox-template-'.getmypid().'-'.bin2hex(random_bytes(3));
        File::ensureDirectoryExists($dir);
        $path = config('sandbox.path');
        config(['sandbox.path' => $dir]);
        try {
            $built = app(SandboxTemplateBuilder::class)->build(force: true);
        } finally {
            config(['sandbox.path' => $path]);
        }

        return self::$builtTemplate = $built['path'];
    }

    protected function startSandbox(): TestResponse
    {
        return $this->sandboxPost(route('sandbox.start'))->assertRedirect(route('sandbox.home'));
    }

    protected function sandboxId(): ?string
    {
        $token = $this->sandboxJar[Sandbox::COOKIE] ?? null;

        return $token === null ? null : SandboxFiles::idFor($token);
    }

    protected function switchRole(string $role): TestResponse
    {
        return $this->sandboxPost(route('sandbox.role'), ['role' => $role])->assertRedirect();
    }

    protected function sandboxGet(string $uri): TestResponse
    {
        return $this->remember($this->withCookies($this->sandboxJar)->get($uri));
    }

    protected function sandboxPost(string $uri, array $data = []): TestResponse
    {
        return $this->remember($this->withCookies($this->sandboxJar)->post($uri, $data));
    }

    /** Runs $callback with the sandbox's own database, as a request inside it would. */
    protected function insideSandbox(callable $callback): mixed
    {
        $sandbox = app(Sandbox::class);
        $sandbox->activate($this->sandboxId());
        try {
            return $callback();
        } finally {
            $sandbox->deactivate();
        }
    }

    private function remember(TestResponse $response): TestResponse
    {
        foreach ([Sandbox::COOKIE, Sandbox::SESSION_COOKIE] as $name) {
            $cookie = $response->getCookie($name, false);
            if ($cookie === null) {
                continue;
            }
            if ($cookie->getValue() === null || $cookie->isCleared()) {
                unset($this->sandboxJar[$name]);
            } else {
                $this->sandboxJar[$name] = $response->getCookie($name)->getValue();
            }
        }
        $this->defaultCookies = [];

        return $response;
    }
}
