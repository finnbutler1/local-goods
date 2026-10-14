<?php

namespace App\Http\Middleware;

use App\Http\Controllers\JudgeLinkController;
use App\Services\ReviewerLinks;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a reviewer's session on their next request once it should no longer
 * work: they were revoked, judging ended, or the admin made a new link (the
 * session's fingerprint no longer matches). Works with any session driver,
 * including the cookie sessions used in production.
 */
class EndReviewerSessions
{
    public function __construct(private ReviewerLinks $links) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->isRevoked()
            || ($user->isReadOnly() && ! $this->links->sessionIsCurrent($user, $request->session()->get(ReviewerLinks::SESSION_KEY))))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Opening a (new) magic link carries on signed out, so it can sign them in again.
            if (! $request->routeIs('judge-access.*')) {
                return JudgeLinkController::ended();
            }
        }

        return $next($request);
    }
}
