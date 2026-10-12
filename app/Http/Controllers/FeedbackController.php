<?php

namespace App\Http\Controllers;

use App\Enums\FeedbackCategory;
use App\Enums\FeedbackSeverity;
use App\Models\Feedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The Feedback button on every signed-in page, and editing your own items
 * from /account. Judges may use both (BlockJudgeWrites allows these routes).
 */
class FeedbackController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Feedback::class);

        $request->user()->feedback()->create($this->validated($request, 'feedback'));

        return back()->with('feedback_status', 'Thanks, your feedback was saved.');
    }

    public function update(Request $request, Feedback $feedback): RedirectResponse
    {
        Gate::authorize('update', $feedback);

        $feedback->update($this->validated($request, "feedback-{$feedback->id}"));

        return redirect()->to(route('account').'#feedback-'.$feedback->id)
            ->with('feedback_status', 'Your feedback was updated.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $bag): array
    {
        return $request->validateWithBag($bag, [
            'page_url' => ['required', 'string', 'max:2048'],
            'category' => ['required', Rule::enum(FeedbackCategory::class)],
            'rating' => ['required', 'integer', 'between:1,5'],
            'severity' => ['required', Rule::enum(FeedbackSeverity::class)],
            'comment' => ['required', 'string', 'max:5000'],
        ], [
            'rating.required' => 'Choose a rating from 1 to 5.',
            'comment.required' => 'Tell us a little about what you saw.',
        ]);
    }
}
