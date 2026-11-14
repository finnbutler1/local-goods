<?php

namespace App\Http\Middleware;

use App\Services\Sandbox\Sandbox;
use App\Services\Sandbox\SandboxFiles;
use App\Services\Sandbox\SandboxRoutes;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web group, sorted to run after EncryptCookies and before StartSession
 * (bootstrap/app.php). Does nothing while sandboxes are off (ADR 0045).
 *
 * With a valid cookie for a live sandbox, the whole request runs inside it
 * (Sandbox::activate) and is put back afterwards, whatever happens. Only the
 * pages in SandboxRoutes open; anything else is a 404 page that links back.
 * /sandbox pages, and every request inside a sandbox, use the sandbox's own
 * session cookie: the live login's session is never read or written.
 *
 * A cookie that doesn't open a live sandbox (ended, deleted, or tampered
 * with: Laravel encrypts and signs it, so a changed value reads as nothing)
 * is cleared, and the visitor is sent to /sandbox to start again.
 */
class UseSandbox
{
    public function __construct(private Sandbox $sandbox, private SandboxFiles $files) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Sandbox::enabled()) {
            return $next($request);
        }

        $token = $request->cookies->get(Sandbox::COOKIE);
        $id = is_string($token) && preg_match(SandboxFiles::TOKEN_PATTERN, $token) ? SandboxFiles::idFor($token) : null;
        $live = $id !== null && $this->files->isLive($id);
        $onSandboxPage = $request->is('sandbox') || $request->is('sandbox/*');

        if ($request->cookies->has(Sandbox::COOKIE) && ! $live) {
            Cookie::queue(Cookie::forget(Sandbox::COOKIE));
            if (! $onSandboxPage) {
                return redirect()->route('sandbox.show', ['ended' => 1]);
            }
        }

        if (! $live && ! $onSandboxPage) {
            return $next($request);
        }

        $sessionCookie = config('session.cookie');
        $guard = config('auth.defaults.guard');
        config(['session.cookie' => Sandbox::SESSION_COOKIE]);

        try {
            if (! $live) {
                // /sandbox before one exists: the sandbox guard, which finds nobody here,
                // so no live login (a judge's included) is ever read on these pages.
                Auth::shouldUse(Sandbox::GUARD);

                return $next($request);
            }

            $this->sandbox->activate($id);
            $route = $request->route()?->getName();

            if (in_array($route, ['home', 'dashboard'], true)) {
                return redirect()->route('sandbox.home');
            }
            if (! SandboxRoutes::allows($route)) {
                return response()->view('sandbox.not-here', [], 404);
            }

            return $next($request);
        } finally {
            $this->sandbox->finishRequest();
            Auth::shouldUse($guard);
            config(['session.cookie' => $sessionCookie]);
        }
    }
}
