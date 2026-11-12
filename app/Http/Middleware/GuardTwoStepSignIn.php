<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every Fortify route (config/fortify.php) and acts only on the
 * two-step sign-in ones:
 *
 * - Turning it on, confirming, turning it off and new recovery codes: only an
 *   account that may use it (User::mayUseTwoStepSignIn). The four shared demo
 *   logins and judges are refused here, on the server, whatever the page shows.
 * - Fortify's JSON pages for the QR code, the setup key and the recovery
 *   codes: always "not found". Our own page shows the QR code and key only
 *   while setup isn't finished, and recovery codes only once, right after
 *   they are made, so neither can be read back later.
 *
 * The sign-in code page (two-factor.login) is untouched.
 */
class GuardTwoStepSignIn
{
    public const MANAGE_ROUTES = [
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.regenerate-recovery-codes',
    ];

    public const READ_BACK_ROUTES = [
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()?->getName();

        if (in_array($route, self::READ_BACK_ROUTES, true)) {
            abort(404);
        }

        // Signed out: left to the route's own auth check, which sends them to log in.
        $user = $request->user();

        if (in_array($route, self::MANAGE_ROUTES, true) && $user && ! $user->mayUseTwoStepSignIn()) {
            abort(403, 'Two-step sign-in can\'t be turned on for this account.');
        }

        return $next($request);
    }
}
