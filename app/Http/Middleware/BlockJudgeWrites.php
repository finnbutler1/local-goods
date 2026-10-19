<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Judges (every invited reviewer) can look at everything but change nothing.
 * Any request from a judge that could change data (anything but GET, HEAD or
 * OPTIONS) gets 403, except the few routes listed below. Runs on every web
 * route, so a route added later is blocked unless it is added here.
 * Policies and gates check the same thing again in the controllers.
 */
class BlockJudgeWrites
{
    /**
     * The only routes a judge may send changes to: their own session (magic
     * link, log out), feedback, and blind adjudication votes (ADR 0011; the
     * `adjudicate` gate then lets only BID, contest-judge and personal-contact
     * reviewers through; KeepPersonalContactsToAdjudication narrows personal
     * contacts further, ADR 0019).
     */
    public const ALLOWED_ROUTES = [
        'judge-access.store',
        'logout',
        'feedback.store',
        'feedback.update',
        'adjudication.start',
        'adjudication.vote',
        'adjudication.submit',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isReadOnly()
            && ! $request->isMethodSafe()
            && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            abort(403, 'Your account is read-only.');
        }

        return $next($request);
    }
}
