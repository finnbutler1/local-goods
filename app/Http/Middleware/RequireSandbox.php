<?php

namespace App\Http\Middleware;

use App\Services\Sandbox\Sandbox;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware for the sandbox pages (ADR 0045).
 *
 * `sandbox.enabled`: 404 unless sandboxes are on (SANDBOX_ENABLED and an
 * SQLite driver). `sandbox.only`: also 404 unless this request is running
 * inside a live sandbox. The role bar is behind `sandbox.only`, so on the
 * live site, with no sandbox, it doesn't exist.
 */
class RequireSandbox
{
    public function handle(Request $request, Closure $next, string $mode = 'enabled'): Response
    {
        abort_unless(Sandbox::enabled(), 404);
        abort_if($mode === 'active' && ! Sandbox::active(), 404);

        return $next($request);
    }
}
