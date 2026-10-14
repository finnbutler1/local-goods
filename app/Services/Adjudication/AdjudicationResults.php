<?php

namespace App\Services\Adjudication;

use App\Models\AdjudicationCase;
use App\Models\AdjudicationParticipant;
use App\Models\AdjudicationSet;
use App\Models\AdjudicationVote;
use App\Services\Fraud\FraudEvalPatterns;
use App\Services\Fraud\FraudEvalRunner;
use App\Support\WilsonInterval;
use Illuminate\Support\Collection;

/**
 * What the blind adjudicators said (ADR 0011). Only reviewers who pressed
 * Submit all count. On each disputed case the majority of votes sides with
 * the AI or with Finn's label; a tie is "split". Control cases (where both
 * agreed) measure how reliable the adjudicators are.
 *
 * Reported on its own: it never changes a label or a headline number.
 */
class AdjudicationResults
{
    /**
     * Results from every submitted adjudicator, and from only those who
     * said they had not looked at the eval results page before.
     *
     * @return array{all: array<string, mixed>, unseen: array<string, mixed>}|null null until someone submits
     */
    public function for(AdjudicationSet $set): ?array
    {
        $participants = $set->participants()->whereNotNull('submitted_at')->with('user')->orderBy('id')->get();

        if ($participants->isEmpty()) {
            return null;
        }

        // Numbered once, so "Adjudicator 2" is the same person in both views.
        $numbers = $participants->pluck('user_id')->flip()->map(fn ($i) => $i + 1);
        $cases = $set->cases()->orderBy('case_id')->get();
        $votes = AdjudicationVote::whereIn('adjudication_case_id', $cases->pluck('id'))
            ->whereIn('user_id', $participants->pluck('user_id'))
            ->get()->groupBy('adjudication_case_id');

        return [
            'all' => $this->summarize($cases, $votes, $participants, $numbers),
            'unseen' => $this->summarize($cases, $votes, $participants->reject->seen_eval_before->values(), $numbers),
        ];
    }

    /**
     * @param  Collection<int, AdjudicationCase>  $cases
     * @param  Collection<int, Collection<int, AdjudicationVote>>  $votes  grouped by case id
     * @param  Collection<int, AdjudicationParticipant>  $participants
     * @param  Collection<int, int>  $numbers  user id => adjudicator number
     * @return array<string, mixed>
     */
    private function summarize(Collection $cases, Collection $votes, Collection $participants, Collection $numbers): array
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
                'majority' => $majority,
                'sided_with' => match (true) {
                    $caseVotes->isEmpty(), $majority === null => 'split',
                    $majority === $case->ai_label => 'ai',
                    default => 'finn',
                },
                'votes' => $caseVotes->map(fn (AdjudicationVote $v) => [
                    'number' => $numbers[$v->user_id],
                    'name' => $byUser[$v->user_id]->user->name,
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

        $tally = fn (Collection $group) => [
            'cases' => $group->count(),
            'ai' => $group->where('sided_with', 'ai')->count(),
            'finn' => $group->where('sided_with', 'finn')->count(),
            'split' => $group->where('sided_with', 'split')->count(),
        ];

        $summary = $tally($disputed);

        return [
            'adjudicators' => $participants->map(fn (AdjudicationParticipant $p) => [
                'number' => $numbers[$p->user_id],
                'name' => $p->user->name,
                'type' => $p->user->reviewer_type?->label(),
                'seen_eval_before' => $p->seen_eval_before,
            ])->values()->all(),
            'by_type' => $participants->countBy(fn ($p) => $p->user->reviewer_type?->label())->all(),
            'summary' => $summary,
            'sentence' => $participants->isEmpty()
                ? 'No adjudicators in this group yet.'
                : "On {$summary['cases']} disputed cases, adjudicators sided with the AI in {$summary['ai']}, with Finn in {$summary['finn']}, split in {$summary['split']}.",
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
