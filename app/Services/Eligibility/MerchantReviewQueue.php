<?php

namespace App\Services\Eligibility;

use App\Models\MerchantReviewDecision;
use Illuminate\Support\Collection;

/**
 * The BID review queue for Merchant Eligibility Matching: every candidate
 * the AI has assessed (data/merchant_assessments.json), with its public
 * facts and the log of decisions people made. Never includes hand labels,
 * so a reviewer's decision is not steered by them.
 */
class MerchantReviewQueue
{
    /**
     * Waiting candidates first (flagged for review before the rest), then
     * decided ones, each in candidate order.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function items(): Collection
    {
        $assessments = MerchantEvalReport::assessments();
        $candidates = MerchantCandidates::load();
        $activity = MerchantCandidates::activity();
        $decisions = MerchantReviewDecision::with('user')
            ->whereIn('candidate_id', array_keys($assessments))
            ->latest('created_at')->latest('id')->get()
            ->groupBy('candidate_id');

        return collect($assessments)
            ->filter(fn ($a, $id) => isset($candidates[$id]))
            ->map(function (array $assessment, string $id) use ($candidates, $activity, $decisions) {
                $history = $decisions->get($id, collect());

                return [
                    'id' => $id,
                    'candidate' => $candidates[$id] + ['business_activity' => $activity[$id] ?? ''],
                    'assessment' => $assessment,
                    'decisions' => $history,
                    'latest' => $history->first(),
                ];
            })
            ->sortBy(fn ($item) => [$item['latest'] !== null, ! $item['assessment']['needs_human_review'], $item['id']])
            ->values();
    }

    /**
     * Totals only, with no business named: what the public judge account sees.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function totals(Collection $items): array
    {
        return [
            'assessed' => $items->count(),
            'candidates' => count(MerchantCandidates::load()),
            'needs_review' => $items->filter(fn ($i) => $i['assessment']['needs_human_review'])->count(),
            'by_classification' => collect(MerchantEvalReport::CLASS_NAMES)
                ->map(fn ($name, $class) => $items->where('assessment.classification', $class)->count())->all(),
            'by_status' => collect(MerchantReviewDecision::DECISIONS)
                ->map(fn ($name, $decision) => $items->filter(fn ($i) => $i['latest']?->decision === $decision)->count())
                ->put('waiting', $items->whereNull('latest')->count())
                ->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function assessmentFor(string $candidateId): ?array
    {
        return MerchantEvalReport::assessments()[$candidateId] ?? null;
    }
}
