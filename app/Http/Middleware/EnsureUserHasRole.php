<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Support\ViewAs;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: `role:bid_admin,platform_admin` lets only those roles in.
 * Anyone else who is signed in gets 403. Use after `auth`.
 *
 * Judges may also look (GET or HEAD only) at the pages of the roles they can
 * view as (ViewAs::ROLES), never at platform-admin pages, which hold real
 * reviewers' names and emails.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $role) => Role::from($role), $roles);
        $user = $request->user();

        $judgeMayLook = $user?->isReadOnly()
            && $request->isMethodSafe()
            && array_intersect(array_map(fn (Role $r) => $r->value, $allowed), array_map(fn (Role $r) => $r->value, ViewAs::ROLES)) !== [];

        abort_unless($user?->hasRole(...$allowed) || $judgeMayLook, 403);

        return $next($request);
    }
}
