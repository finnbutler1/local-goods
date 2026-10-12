<?php

namespace App\Support;

use App\Enums\Role;
use Illuminate\Http\Request;

/**
 * Judges can view the app as a BID admin, employer admin, employee or
 * merchant, read-only. The choice is kept in the session; a judge who opens
 * one of those pages by a direct link sees it as that page's role.
 */
class ViewAs
{
    public const SESSION_KEY = 'view_as';

    /** The roles a judge can view as, in tour order. */
    public const ROLES = [Role::Employee, Role::BidAdmin, Role::EmployerAdmin, Role::Merchant];

    /** The role the signed-in judge is viewing this page as, or null on their own pages. */
    public static function current(Request $request): ?Role
    {
        if (! $request->user()?->isReadOnly()) {
            return null;
        }

        $pageRoles = self::pageRoles($request);
        $viewable = array_values(array_filter(self::ROLES, fn (Role $r) => in_array($r, $pageRoles, true)));

        if ($viewable === []) {
            return null;
        }

        $chosen = Role::tryFrom((string) $request->session()->get(self::SESSION_KEY));

        return in_array($chosen, $viewable, true) ? $chosen : $viewable[0];
    }

    /** @return list<Role> The roles named in this route's role: middleware. */
    public static function pageRoles(Request $request): array
    {
        foreach ($request->route()?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'role:')) {
                return array_map(fn ($r) => Role::from($r), explode(',', substr($middleware, 5)));
            }
        }

        return [];
    }
}
