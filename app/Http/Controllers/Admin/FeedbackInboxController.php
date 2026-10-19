<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FeedbackCategory;
use App\Enums\FeedbackSeverity;
use App\Enums\FeedbackStatus;
use App\Enums\ReviewerType;
use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Platform admin only: every piece of feedback, filterable, with a status
 * and the commit that fixed it, and the average rating per rubric category.
 */
class FeedbackInboxController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-feedback');

        $filters = $request->validate([
            'category' => ['nullable', Rule::enum(FeedbackCategory::class)],
            'severity' => ['nullable', Rule::enum(FeedbackSeverity::class)],
            'status' => ['nullable', Rule::enum(FeedbackStatus::class)],
            'reviewer_type' => ['nullable', Rule::enum(ReviewerType::class)],
            'reviewer' => ['nullable', 'integer'],
        ]);

        $items = Feedback::with('user')
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['reviewer'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['reviewer_type'] ?? null, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('reviewer_type', $v)))
            ->latest()->latest('id')
            ->get();

        $averages = Feedback::selectRaw('category, count(*) as reviews, avg(rating) as average')
            ->groupBy('category')->get()->keyBy(fn ($row) => $row->getRawOriginal('category'));

        return view('admin.feedback', [
            'items' => $items,
            'filters' => $filters,
            'averages' => $averages,
            'reviewers' => User::whereHas('feedback')->orderBy('name')->get(['id', 'name', 'role', 'reviewer_type']),
        ]);
    }

    public function update(Request $request, Feedback $feedback): RedirectResponse
    {
        Gate::authorize('manage-feedback');

        $data = $request->validate([
            'status' => ['required', Rule::enum(FeedbackStatus::class)],
            'fixed_commit_sha' => ['nullable', 'regex:/^[0-9a-f]{7,40}$/i'],
        ], [
            'fixed_commit_sha.regex' => 'A commit SHA is 7 to 40 letters a–f and digits, like 4c6ac43.',
        ]);

        $status = FeedbackStatus::from($data['status']);

        $feedback->forceFill([
            'status' => $status,
            'fixed_commit_sha' => isset($data['fixed_commit_sha']) ? strtolower($data['fixed_commit_sha']) : null,
            'status_changed_at' => $status === $feedback->status ? $feedback->status_changed_at : now(),
        ])->save();

        return redirect()->to(url()->previous(route('admin.feedback.index'))."#feedback-{$feedback->id}")
            ->with('status', 'Saved.');
    }
}
