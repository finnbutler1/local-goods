<?php

namespace App\Http\Controllers;

use App\Services\ReviewerLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * A reviewer's magic link opens /judge-access#<id>.<token>. The page moves
 * the part after # into a form and posts it here, which signs them straight
 * into their read-only reviewer account. No password, no account setup.
 */
class JudgeLinkController extends Controller
{
    public function __construct(private ReviewerLinks $links) {}

    public function show(): View
    {
        return view('judge-access.show');
    }

    public function store(Request $request): RedirectResponse|Response
    {
        $link = $request->input('link');
        $user = is_string($link) ? $this->links->userFor($link) : null;

        if (! $user) {
            return self::ended();
        }

        // Start clean, even if someone else was signed in on this browser.
        $request->session()->invalidate();
        Auth::guard('web')->login($user);
        $request->session()->regenerateToken();
        $request->session()->put(ReviewerLinks::SESSION_KEY, $this->links->sessionFingerprint($user));

        // Personal contacts only vote (ADR 0019), so they skip the judge home.
        return redirect()->route($user->isPersonalContact() ? 'adjudication.show' : 'judge.dashboard');
    }

    /** The same friendly page whatever the reason, so it never hints at which part was wrong. */
    public static function ended(): Response
    {
        return response()->view('judge-access.ended', status: 410);
    }
}
