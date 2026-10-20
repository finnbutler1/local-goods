<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Personal contacts (friends, family, classmates; ADR 0019) only vote in the
 * blind adjudication. They get the voting page, the feedback widget and their
 * own account page (where they edit their feedback), nothing else: no judge
 * home or tour, no view-as, no eval or admin pages. Runs after
 * BlockJudgeWrites, so any write they make is also on its allowlist.
 */
class KeepPersonalContactsToAdjudication
{
    /** Every route a personal contact may open or post to. */
    public const ALLOWED_ROUTES = [
        'adjudication.show',
        'adjudication.start',
        'adjudication.vote',
        'adjudication.submit',
        'adjudication.more',
        'adjudication.finish',
        'feedback.store',
        'feedback.update',
        'account',
        'dashboard',
        'logout',
        'judge-access.show',
        'judge-access.store',
    ];

    /** Where other reviewers land; personal contacts go straight to voting instead. */
    public const SENT_TO_VOTING = ['home', 'judge.dashboard'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isPersonalContact()) {
            return $next($request);
        }

        $route = $request->route()?->getName();

        if ($request->isMethodSafe() && in_array($route, self::SENT_TO_VOTING, true)) {
            return redirect()->route('adjudication.show');
        }

        abort_unless(in_array($route, self::ALLOWED_ROUTES, true), 403, 'Your link is only for checking the AI\'s cases.');

        return $next($request);
    }
}
