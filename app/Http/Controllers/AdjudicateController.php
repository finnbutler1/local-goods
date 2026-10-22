<?php

namespace App\Http\Controllers;

use App\Models\AdjudicationCase;
use App\Models\AdjudicationParticipant;
use App\Models\AdjudicationSet;
use App\Models\AdjudicationVote;
use App\Services\Adjudication\AdjudicationAssigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Help check the AI": named BID and contest-judge reviewers (and personal
 * contacts, reported apart) vote, blind, on eval cases one at a time
 * (ADR 0011), in batches of 10 they can repeat until they've seen every case
 * (ADR 0020). They see only the facts of each case,
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

    public function __construct(private AdjudicationAssigner $assigner) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        $set = AdjudicationSet::current();
        $participant = $set?->participantFor($user);

        if ($set === null || ($set->isClosed() && $participant === null)) {
            return view('adjudicate.show', ['state' => 'none', 'set' => $set]);
        }

        $canVote = Gate::allows('adjudicate');
        $batch = $participant?->openBatch() ?? collect();
        $had = $participant?->assignments()->count() ?? 0;

        $state = match (true) {
            $participant?->hasSubmitted() => 'submitted',
            $set->isClosed() => 'closed',
            ! $canVote => 'view-only',
            $participant === null || $had === 0 || $request->boolean('start') => 'question',
            $batch->isNotEmpty() => 'voting',
            default => 'more',
        };

        $cases = match ($state) {
            'voting' => $batch->pluck('adjudicationCase')->values(),
            'view-only' => $set->casesInOrderFor($user),
            default => collect(),
        };
        $votes = AdjudicationVote::where('user_id', $user->id)
            ->whereIn('adjudication_case_id', $cases->pluck('id'))
            ->get()->keyBy('adjudication_case_id');

        $answered = $cases->filter(fn ($c) => $votes->has($c->id))->count();
        $firstUnanswered = $cases->search(fn ($c) => ! $votes->has($c->id));
        $default = $firstUnanswered === false ? $cases->count() + 1 : $firstUnanswered + 1;
        $n = max(1, min($cases->count() + 1, (int) $request->query('n', $default)));
        $totalCases = $set->cases()->count();

        return view('adjudicate.show', [
            'state' => $state,
            'set' => $set,
            'participant' => $participant,
            'cases' => $cases,
            'votes' => $votes,
            'answered' => $answered,
            'n' => $state === 'view-only' ? min($n, $cases->count()) : $n,
            'case' => $cases[$n - 1] ?? ($state === 'view-only' ? $cases->first() : null),
            'had' => $had,
            'totalCases' => $totalCases,
            'nextBatchSize' => min(AdjudicationAssigner::BATCH_SIZE, $totalCases - $had),
            'counted' => $participant ? $participant->assignments()->whereNotNull('submitted_at')->count() : 0,
        ]);
    }

    /**
     * The opening question: "Have you looked at the eval results page before
     * today?" Answering it the first time hands out the first batch of 10.
     */
    public function start(Request $request): RedirectResponse
    {
        Gate::authorize('adjudicate');
        $set = $this->openSet();
        abort_if($set->hasSubmitted($request->user()), 403, 'You have already submitted.');

        $data = $request->validate(['seen_eval_before' => ['required', Rule::in(['yes', 'no'])]], [
            'seen_eval_before.required' => 'Please answer yes or no.',
        ]);

        $participant = $set->participants()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['seen_eval_before' => $data['seen_eval_before'] === 'yes'],
        );

        if (! $participant->assignments()->exists()) {
            $this->assigner->nextBatch($participant);
        }

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

        // Only the cases in the batch they are working on (ADR 0020).
        abort_unless($participant->openBatch()->contains('adjudication_case_id', $adjudicationCase->id), 403, 'This case is not in your current batch.');

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

    /** Submit the current batch. Its votes count from now on, even if they stop here. */
    public function submit(Request $request): RedirectResponse
    {
        Gate::authorize('adjudicate');
        $set = $this->openSet();
        $participant = $this->participant($set, $request) ?? abort(403, 'Please answer the question first.');
        $batch = $participant->openBatch();
        abort_if($batch->isEmpty(), 403, 'There is no batch to submit.');

        $voted = AdjudicationVote::where('user_id', $request->user()->id)
            ->whereIn('adjudication_case_id', $batch->pluck('adjudication_case_id'))->count();

        if ($voted < $batch->count()) {
            return redirect()->route('adjudication.show')->withErrors(['submit' => 'Please answer every case before you submit.']);
        }

        $participant->assignments()->whereKey($batch->modelKeys())->update(['submitted_at' => now()]);

        // Every case seen: they're finished.
        if ($participant->assignments()->count() >= $set->cases()->count()) {
            $participant->forceFill(['submitted_at' => now()])->save();
        }

        return redirect()->route('adjudication.show')->with('status', "Thanks! Your {$batch->count()} ".str('vote')->plural($batch->count()).' are in.');
    }

    /** "Want to do 10 more?" Yes. */
    public function more(Request $request): RedirectResponse
    {
        Gate::authorize('adjudicate');
        $set = $this->openSet();
        $participant = $this->participant($set, $request) ?? abort(403, 'Please answer the question first.');
        abort_if($participant->openBatch()->isNotEmpty(), 403, 'Please submit the batch you are on first.');

        if ($this->assigner->nextBatch($participant)->isEmpty()) {
            $participant->forceFill(['submitted_at' => now()])->save();
        }

        return redirect()->route('adjudication.show');
    }

    /** "Want to do 10 more?" No thanks: they're finished, and the eval pages open up for them. */
    public function finish(Request $request): RedirectResponse
    {
        Gate::authorize('adjudicate');
        $set = $this->openSet();
        $participant = $this->participant($set, $request) ?? abort(403, 'Please answer the question first.');
        abort_if($participant->openBatch()->isNotEmpty(), 403, 'Please submit the batch you are on first.');

        $participant->forceFill(['submitted_at' => now()])->save();

        return redirect()->route('adjudication.show')->with('status', 'Thank you. Your votes are in.');
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
