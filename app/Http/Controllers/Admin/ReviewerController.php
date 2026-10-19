<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReviewerLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Platform admin only: invite reviewers, make a new magic link, revoke access.
 * A link is shown once, right after it is made: only its hash is kept.
 */
class ReviewerController extends Controller
{
    public function __construct(private ReviewerLinks $links) {}

    public function index(): View
    {
        Gate::authorize('manage-reviewers');

        $reviewers = User::where('role', Role::Judge)->latest('invited_at')->latest('id')->get();

        return view('admin.reviewers', [
            'public' => $reviewers->first(fn (User $user) => $user->isPublicReviewer()),
            'reviewers' => $reviewers->reject(fn (User $user) => $user->isPublicReviewer()),
            'ended' => $this->links->hasEnded(),
            'types' => array_filter(ReviewerType::cases(), fn (ReviewerType $type) => $type !== ReviewerType::Public),
            'endsAt' => $this->links->endsAt(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-reviewers');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            // Named reviewers only: the one public account comes from demo:seed-accounts.
            'reviewer_type' => ['required', Rule::enum(ReviewerType::class)->except(ReviewerType::Public)],
        ]);

        [$user, $link] = $this->links->invite($data['name'], $data['email'], ReviewerType::from($data['reviewer_type']));

        return redirect()->route('admin.reviewers.index')
            // Not ReviewerLinks::SESSION_KEY: that holds a judge's session fingerprint, a string.
            ->with('new_reviewer_link', ['name' => $user->name, 'url' => $link]);
    }

    public function link(User $user): RedirectResponse
    {
        Gate::authorize('manage-reviewers');
        abort_unless($user->role === Role::Judge, 404);

        return redirect()->route('admin.reviewers.index')
            ->with('new_reviewer_link', ['name' => $user->name, 'url' => $this->links->newLink($user)]);
    }

    public function revoke(User $user): RedirectResponse
    {
        Gate::authorize('manage-reviewers');
        abort_unless($user->role === Role::Judge, 404);

        $this->links->revoke($user);

        return redirect()->route('admin.reviewers.index')
            ->with('status', "{$user->name}'s link no longer works, and any open session ends on its next click.");
    }
}
