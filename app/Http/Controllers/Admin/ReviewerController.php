<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReviewerInvites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Platform admin only: invite reviewers, make a new link, revoke access.
 * The invite link is shown once, right after it is made.
 */
class ReviewerController extends Controller
{
    public function __construct(private ReviewerInvites $invites) {}

    public function index(): View
    {
        Gate::authorize('manage-reviewers');

        return view('admin.reviewers', [
            'reviewers' => User::where('role', Role::Judge)->latest('invited_at')->latest('id')->get(),
            'types' => ReviewerType::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-reviewers');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'reviewer_type' => ['required', Rule::enum(ReviewerType::class)],
        ]);

        [$user, $link] = $this->invites->invite($data['name'], $data['email'], ReviewerType::from($data['reviewer_type']));

        return redirect()->route('admin.reviewers.index')
            ->with('invite_link', ['name' => $user->name, 'url' => $link]);
    }

    public function link(User $user): RedirectResponse
    {
        Gate::authorize('manage-reviewers');
        abort_unless($user->role === Role::Judge, 404);

        return redirect()->route('admin.reviewers.index')
            ->with('invite_link', ['name' => $user->name, 'url' => $this->invites->newLink($user)]);
    }

    public function revoke(User $user): RedirectResponse
    {
        Gate::authorize('manage-reviewers');
        abort_unless($user->role === Role::Judge, 404);

        $this->invites->revoke($user);

        return redirect()->route('admin.reviewers.index')
            ->with('status', "{$user->name} can no longer log in. Any open session was closed.");
    }
}
