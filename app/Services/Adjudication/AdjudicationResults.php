<?php

namespace App\Services\Adjudication;

use App\Models\AdjudicationAssignment;
use App\Models\AdjudicationCase;
use App\Models\AdjudicationParticipant;
use App\Models\AdjudicationSet;
use App\Models\AdjudicationVote;
use App\Services\Fraud\FraudEvalPatterns;
use App\Services\Fraud\FraudEvalRunner;
use App\Support\WilsonInterval;
use Illuminate\Support\Collection;

/**
 * What the blind adjudicators said (ADR 0011). A vote counts once its batch
 * of 10 is submitted (ADR 0020), so someone who stops after one batch still
 * counts; votes from before batches count if the reviewer pressed Submit all.
 * On each disputed case the majority of votes sides with the AI or with
 * Finn's label; a tie is "split", and a case with fewer than MIN_VOTES votes
 * in the group is "too few votes to call". Control cases (where both agreed)
 * measure how reliable the adjudicators are.
 *
 * Reported on its own: it never changes a label or a headline number.
 * Personal contacts' votes (ADR 0019) are a separate group, never merged
 * into the BID and contest-judge result.
 */
class AdjudicationResults
{
    /** A case needs this many votes in a group before the group's majority is reported. */
    public const MIN_VOTES = 3;

    /**
     * The headline: every BID and contest-judge adjudicator with a counted vote, and
     * only those who said they had not looked at the eval results page
     * before. Personal contacts (ADR 0019) get the same two views under
     * 'personal', numbered on their own and never counted in the headline.
     *
     * @return array{all: array<string, mixed>, unseen: array<string, mixed>, personal: array{all: array<string, mixed>, unseen: array<string, mixed>}|null}|null null until someone submits
     */
    public function for(AdjudicationSet $set): ?array
    {
        $cases = $set->cases()->orderBy('case_id')->get();
        $counted = $this->countedCases($set, $cases);
        $participants = $set->participants()->with('user')->orderBy('id')->get()
            ->filter(fn (AdjudicationParticipant $p) => ($counted[$p->id] ?? collect())->isNotEmpty())->values();

        if ($participants->isEmpty()) {
            return null;
        }

        $caseIdsFor = $participants->mapWithKeys(fn ($p) => [$p->user_id => $counted[$p->id]->flip()]);
        $votes = AdjudicationVote::whereIn('adjudication_case_id', $cases->pluck('id'))
            ->whereIn('user_id', $participants->pluck('user_id'))
            ->get()
            ->filter(fn (AdjudicationVote $v) => $caseIdsFor[$v->user_id]->has($v->adjudication_case_id))
            ->groupBy('adjudication_case_id');

        [$headline, $personal] = $participants->partition(fn (AdjudicationParticipant $p) => ! $p->user->isPersonalContact());

        return [
            ...$this->group($cases, $votes, $headline->values(), 'adjudicators'),
            'personal' => $personal->isEmpty() ? null : $this->group($cases, $votes, $personal->values(), 'personal contacts'),
        ];
    }

    /**
     * Which cases' votes count for each participant: those in their submitted
     * batches, or every case for someone who pressed Submit all before batches.
     *
     * @return Collection<int, Collection<int, int>> participant id => case ids
     */
    private function countedCases(AdjudicationSet $set, Collection $cases): Collection
    {
        $participantIds = $set->participants()->pluck('id');
        $assignments = AdjudicationAssignment::whereIn('adjudication_participant_id', $participantIds)->get()
            ->groupBy('adjudication_participant_id');

        return $set->participants()->get()->mapWithKeys(fn (AdjudicationParticipant $p) => [
            $p->id => isset($assignments[$p->id])
                ? $assignments[$p->id]->whereNotNull('submitted_at')->pluck('adjudication_case_id')->values()
                : ($p->hasSubmitted() ? $cases->pluck('id') : collect()),
        ]);
    }

    /**
     * One group of adjudicators, all of them and only the unseen ones.
     *
     * @return array{all: array<string, mixed>, unseen: array<string, mixed>}
     */
    private function group(Collection $cases, Collection $votes, Collection $participants, string $who): array
    {
        // Numbered once, so "Adjudicator 2" is the same person in both views.
        $numbers = $participants->pluck('user_id')->flip()->map(fn ($i) => $i + 1);

        return [
            'all' => $this->summarize($cases, $votes, $participants, $numbers, $who),
            'unseen' => $this->summarize($cases, $votes, $participants->reject->seen_eval_before->values(), $numbers, $who),
        ];
    }

    /**
     * @param  Collection<int, AdjudicationCase>  $cases
     * @param  Collection<int, Collection<int, AdjudicationVote>>  $votes  grouped by case id
     * @param  Collection<int, AdjudicationParticipant>  $participants
     * @param  Collection<int, int>  $numbers  user id => adjudicator number
     * @param  string  $who  how the sentence names the group
     * @return array<string, mixed>
     */
    private function summarize(Collection $cases, Collection $votes, Collection $participants, Collection $numbers, string $who): array
    {
        $byUser = $participants->keyBy('user_id');
        $votesFor = fn (AdjudicationCase $case) => ($votes[$case->id] ?? collect())
            ->filter(fn ($v) => $byUser->has($v->user_id))
            ->sortBy(fn ($v) => $numbers[$v->user_id])
            ->values();

        $disputed = $cases->where('kind', AdjudicationCase::DISPUTED)->values()->map(function (AdjudicationCase $case) use ($votesFor, $byUser, $numbers) {
            $caseVotes = $votesFor($case);
            $suspicious = $caseVotes->where('label', 'suspicious')->count();
            $normal = $caseVotes->count() - $suspicious;
            $majority = match (true) {
                $caseVotes->count() < self::MIN_VOTES => null,
                $suspicious > $normal => 'suspicious',
                $normal > $suspicious => 'normal',
                default => null,
            };

            return [
                'case_id' => $case->case_id,
                'split' => $case->split,
                'tags' => $case->tags,
                'facts' => $case->facts,
                'my_label' => $case->my_label,
                'ai_label' => $case->ai_label,
                'normal' => $normal,
                'suspicious' => $suspicious,
                'vote_count' => $caseVotes->count(),
                'majority' => $majority,
                'sided_with' => match (true) {
                    $caseVotes->count() < self::MIN_VOTES => 'too_few',
                    $majority === null => 'split',
                    $majority === $case->ai_label => 'ai',
                    default => 'finn',
                },
                'votes' => $caseVotes->map(fn (AdjudicationVote $v) => [
                    'number' => $numbers[$v->user_id],
                    'name' => $byUser[$v->user_id]->user->adminLabel(),
                    'type' => $byUser[$v->user_id]->user->reviewer_type?->label(),
                    'seen_eval_before' => $byUser[$v->user_id]->seen_eval_before,
                    'label' => $v->label,
                    'reason' => $v->reason,
                ])->all(),
            ];
        });

        $controlVotes = $cases->where('kind', AdjudicationCase::CONTROL)
            ->flatMap(fn (AdjudicationCase $case) => $votesFor($case)->map(fn ($v) => $v->label === $case->my_label));
        $hits = $controlVotes->filter()->count();
        $of = $controlVotes->count();

        // Each figure with the number of votes behind it.
        $tally = fn (Collection $group) => collect(['ai', 'finn', 'split', 'too_few'])
            ->flatMap(fn ($side) => [
                $side => $group->where('sided_with', $side)->count(),
                "{$side}_votes" => $group->where('sided_with', $side)->sum('vote_count'),
            ])
            ->merge(['cases' => $group->count(), 'votes' => $group->sum('vote_count')])
            ->all();

        $summary = $tally($disputed);

        return [
            'adjudicators' => $participants->map(fn (AdjudicationParticipant $p) => [
                'number' => $numbers[$p->user_id],
                'name' => $p->user->adminLabel(),
                'type' => $p->user->reviewer_type?->label(),
                'seen_eval_before' => $p->seen_eval_before,
                'votes' => $votes->flatten(1)->where('user_id', $p->user_id)->count(),
            ])->values()->all(),
            'by_type' => $participants->countBy(fn ($p) => $p->user->reviewer_type?->label())->all(),
            'summary' => $summary,
            'sentence' => $participants->isEmpty()
                ? 'No adjudicators in this group yet.'
                : "On {$summary['cases']} disputed cases ({$summary['votes']} votes), {$who} sided with the AI in {$summary['ai']} ({$summary['ai_votes']} votes), "
                    ."with Finn in {$summary['finn']} ({$summary['finn_votes']} votes), split in {$summary['split']} ({$summary['split_votes']} votes); "
                    .$summary['too_few'].' had too few votes to call (fewer than '.self::MIN_VOTES.').',
            'controls' => [
                'hits' => $hits,
                'of' => $of,
                'pct' => $of === 0 ? null : round(100 * $hits / $of, 1),
                'ci95' => WilsonInterval::percent($hits, $of),
                'cases' => $cases->where('kind', AdjudicationCase::CONTROL)->count(),
            ],
            'by_split' => collect(FraudEvalRunner::SPLITS)
                ->map(fn ($title, $split) => $tally($disputed->where('split', $split)))
                ->filter(fn ($row) => $row['cases'] > 0)
                ->all(),
            'by_tag' => collect(FraudEvalPatterns::TAGS)
                ->map(fn ($title, $tag) => $tally($disputed->filter(fn ($c) => in_array($tag, $c['tags'], true))))
                ->filter(fn ($row) => $row['cases'] > 0)
                ->all(),
            'cases' => $disputed->all(),
        ];
    }
}
