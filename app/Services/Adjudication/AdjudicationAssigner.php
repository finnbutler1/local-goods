<?php

namespace App\Services\Adjudication;

use App\Models\AdjudicationAssignment;
use App\Models\AdjudicationCase;
use App\Models\AdjudicationParticipant;
use App\Models\AdjudicationVote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hands out blind adjudication cases in batches of 10 (ADR 0020): about 7
 * disputed and 3 controls, never a case the person has had before. Within
 * each kind it picks the cases with the fewest votes so far in the person's
 * own group (BID and contest judges, or personal contacts, balanced
 * separately), so every case ends up with a similar number of votes. Ties are
 * broken by a per-person hash, and the batch is shown in a per-person order.
 */
class AdjudicationAssigner
{
    public const BATCH_SIZE = 10;

    public const DISPUTED_PER_BATCH = 7;

    /**
     * Give this participant their next batch. Empty when they have had every case.
     *
     * @return Collection<int, AdjudicationAssignment>
     */
    public function nextBatch(AdjudicationParticipant $participant): Collection
    {
        return DB::transaction(function () use ($participant) {
            $set = $participant->set;
            $had = $participant->assignments()->pluck('adjudication_case_id');
            $load = $this->load($participant);
            $userId = $participant->user_id;

            $rank = fn (Collection $cases) => $cases
                ->sortBy(fn (AdjudicationCase $c) => [$load[$c->id] ?? 0, hash('sha256', "{$set->id}|{$userId}|{$c->case_id}")])
                ->values();
            $remaining = $set->cases()->whereNotIn('id', $had)->get();
            $disputed = $rank($remaining->where('kind', AdjudicationCase::DISPUTED));
            $controls = $rank($remaining->where('kind', AdjudicationCase::CONTROL));

            $picked = $disputed->take(self::DISPUTED_PER_BATCH)
                ->concat($controls->take(self::BATCH_SIZE - self::DISPUTED_PER_BATCH));
            // Too few of one kind left: fill up with the other.
            $picked = $picked->concat($rank($remaining->whereNotIn('id', $picked->pluck('id')))->take(self::BATCH_SIZE - $picked->count()));

            $batch = (int) $participant->assignments()->max('batch') + 1;

            return $picked
                ->sortBy(fn (AdjudicationCase $c) => hash('sha256', "{$set->id}|{$userId}|{$batch}|order|{$c->case_id}"))
                ->values()
                ->map(fn (AdjudicationCase $c, int $i) => $participant->assignments()->create([
                    'adjudication_case_id' => $c->id, 'batch' => $batch, 'position' => $i + 1,
                ]));
        });
    }

    /**
     * How many votes each case has, or will have, in this participant's
     * group: every case given out (submitted or still open), plus the votes
     * of anyone who pressed Submit all before batches existed.
     *
     * @return Collection<int, int> case id => count
     */
    public function load(AdjudicationParticipant $participant): Collection
    {
        $personal = $participant->user->isPersonalContact();
        $group = $participant->set->participants()->with('user')->get()
            ->filter(fn (AdjudicationParticipant $p) => $p->user->isPersonalContact() === $personal)
            ->reject(fn (AdjudicationParticipant $p) => $p->is($participant));

        $given = AdjudicationAssignment::whereIn('adjudication_participant_id', $group->pluck('id'))
            ->get()->countBy('adjudication_case_id');

        $before = $group->filter->submittedBeforeBatches()->pluck('user_id');
        $legacy = AdjudicationVote::whereIn('user_id', $before)
            ->whereIn('adjudication_case_id', $participant->set->cases()->select('id'))
            ->get()->countBy('adjudication_case_id');

        return $given->keys()->merge($legacy->keys())->unique()
            ->mapWithKeys(fn ($caseId) => [$caseId => ($given[$caseId] ?? 0) + ($legacy[$caseId] ?? 0)]);
    }
}
