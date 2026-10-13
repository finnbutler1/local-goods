<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\User;
use App\Services\ReviewerInvites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page an invited reviewer opens from their link to choose a password.
 * The link is signed, expires after 7 days and works once.
 */
class InviteController extends Controller
{
    use PasswordValidationRules;

    public function __construct(private ReviewerInvites $invites) {}

    public function show(Request $request, User $user, string $token): View|Response
    {
        if (! $this->valid($request, $user, $token)) {
            return $this->invalid();
        }

        // A relative address, so the form posts back to whichever host the page came from.
        return view('invite.accept', ['user' => $user, 'action' => $request->getRequestUri()]);
    }

    public function store(Request $request, User $user, string $token): RedirectResponse|Response
    {
        if (! $this->valid($request, $user, $token)) {
            return $this->invalid();
        }

        $request->validate(['password' => $this->passwordRules()]);

        $this->invites->accept($user, $request->input('password'));
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('judge.dashboard');
    }

    private function valid(Request $request, User $user, string $token): bool
    {
        return $request->hasValidRelativeSignature() && $this->invites->tokenMatches($user, $token);
    }

    private function invalid(): Response
    {
        return response()->view('invite.invalid', status: 403);
    }
}
