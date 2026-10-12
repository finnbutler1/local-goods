<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A reviewer whose access was revoked is logged out on their next request,
 * even if their session survived (for example with a non-database session).
 */
class LogOutRevokedUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isRevoked()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Your access has ended. Ask Finn if you think this is a mistake.']);
        }

        return $next($request);
    }
}
