<?php

namespace App\Http\Controllers;

use App\Models\AdjudicationCase;
use App\Models\AdjudicationParticipant;
use App\Models\AdjudicationSet;
use App\Models\AdjudicationVote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Help check the AI": named BID and contest-judge reviewers vote, blind, on
 * eval cases one at a time (ADR 0011). They see only the facts of each case,
 * never the person's label, the AI's call or anyone else's vote. Other
 * reviewers, including the public judge account, can look but not vote.
 *
 * These are the only writes a judge can make besides their session and
 * feedback (BlockJudgeWrites::ALLOWED_ROUTES), and each checks the
 * `adjudicate` gate again.
 */
class AdjudicateController extends Controller
{
    public const REASON_MAX = 500;

    public function show(Request $request): View
    {
        $user = $request->user();
        $set = AdjudicationSet::current();
        $participant = $set?->participantFor($user);

        if ($set === null || ($set->isClosed() && $participant === null)) {
            return view('adjudicate.show', ['state' => 'none', 'set' => $set]);
        }

        $canVote = Gate::allows('adjudicate');
        $cases = $set->casesInOrderFor($user);
        $votes = AdjudicationVote::where('user_id', $user->id)
            ->whereIn('adjudication_case_id', $cases->pluck('id'))
            ->get()->keyBy('adjudication_case_id');

        $state = match (true) {
            $participant?->hasSubmitted() => 'submitted',
            $set->isClosed() => 'closed',
            ! $canVote => 'view-only',
            $participant === null || $request->boolean('start') => 'question',
            default => 'voting',
        };

        $answered = $cases->filter(fn ($c) => $votes->has($c->id))->count();
        $firstUnanswered = $cases->search(fn ($c) => ! $votes->has($c->id));
        $default = $firstUnanswered === false ? $cases->count() + 1 : $firstUnanswered + 1;
        $n = max(1, min($cases->count() + 1, (int) $request->query('n', $default)));

        return view('adjudicate.show', [
            'state' => $state,
            'set' => $set,
            'participant' => $participant,
            'cases' => $cases,
            'votes' => $votes,
            'answered' => $answered,
            'n' => $state === 'view-only' ? min($n, $cases->count()) : $n,
            'case' => $cases[$n - 1] ?? ($state === 'view-only' ? $cases->first() : null),
        ]);
    }

    /** The opening question: "Have you looked at the eval results page before today?" */
    public function start(Request $request): RedirectResponse
    {
        Gate::authorize('adjudicate');
        $set = $this->openSet();
        abort_if($set->hasSubmitted($request->user()), 403, 'You have already submitted.');

        $data = $request->validate(['seen_eval_before' => ['required', Rule::in(['yes', 'no'])]], [
            'seen_eval_before.required' => 'Please answer yes or no.',
        ]);

        $set->participants()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['seen_eval_before' => $data['seen_eval_before'] === 'yes'],
        );

        return redirect()->route('adjudication.show');
    }

    public function vote(Request $request, AdjudicationCase $adjudicationCase): RedirectResponse
    {
        Gate::authorize('adjudicate');
        $set = $this->openSet();
        abort_unless($adjudicationCase->adjudication_set_id === $set->id, 404);
        $participant = $this->participant($set, $request);

        if ($participant === null) {
            return redirect()->route('adjudication.show')->withErrors(['seen_eval_before' => 'Please answer the question first.']);
        }

        $data = $request->validate([
            'label' => ['required', Rule::in(['normal', 'suspicious'])],
            'reason' => ['required', 'string', 'max:'.self::REASON_MAX],
            'n' => ['nullable', 'integer', 'min:1'],
        ], [
            'label.required' => 'Choose normal or suspicious.',
            'reason.required' => 'Write a short reason.',
        ]);

        AdjudicationVote::updateOrCreate(
            ['adjudication_case_id' => $adjudicationCase->id, 'user_id' => $request->user()->id],
            ['label' => $data['label'], 'reason' => trim($data['reason'])],
        );

        return redirect()->route('adjudication.show', ['n' => ($data['n'] ?? 0) + 1]);
    }

    public function submit(Request $request): RedirectResponse
    {
        Gate::authorize('adjudicate');
        $set = $this->openSet();
        $participant = $this->participant($set, $request) ?? abort(403, 'Please answer the question first.');

        $voted = AdjudicationVote::where('user_id', $request->user()->id)
            ->whereIn('adjudication_case_id', $set->cases()->select('id'))->count();

        if ($voted < $set->cases()->count()) {
            return redirect()->route('adjudication.show')->withErrors(['submit' => 'Please answer every case before you submit.']);
        }

        $participant->forceFill(['submitted_at' => now()])->save();

        return redirect()->route('adjudication.show')->with('status', 'Thank you. Your votes are submitted.');
    }

    private function openSet(): AdjudicationSet
    {
        $set = AdjudicationSet::current();
        abort_unless($set?->isOpen(), 403, 'Voting is not open.');

        return $set;
    }

    /** This reviewer's participation, or null if they haven't answered the opening question. 403 once submitted. */
    private function participant(AdjudicationSet $set, Request $request): ?AdjudicationParticipant
    {
        $participant = $set->participantFor($request->user());
        abort_if($participant?->hasSubmitted(), 403, 'You have already submitted. Votes are locked.');

        return $participant;
    }
}
