<?php

namespace App\Services\Adjudication;

use App\Models\AdjudicationCase;
use App\Models\AdjudicationSet;
use App\Services\Fraud\FraudEvalReport;
use App\Services\Fraud\FraudEvalRunner;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds the blind adjudication set from the latest fraud eval run (ADR 0011).
 * A new draft starts with every case where the person's label and the AI
 * disagree, in every split, plus the same number of randomly chosen agreed
 * cases as blind controls. The platform admin can add or remove cases, then
 * publish; after that the set is locked and a change needs a new version.
 *
 * Reads the eval files only. It never changes a label or a headline number.
 */
class AdjudicationSetBuilder
{
    public function __construct(private FraudEvalReport $report, private FraudEvalRunner $runner) {}

    /**
     * Every scored case from the latest run with the facts adjudicators see,
     * keyed by case id, in file order. Empty if there is no run yet.
     *
     * @return array<string, array{case_id: string, kind: string, split: string, my_label: string, ai_label: string, tags: list<string>, facts: array<string, string>}>
     */
    public function candidates(): array
    {
        $run = $this->report->latest();

        if ($run === null) {
            return [];
        }

        $rows = collect($this->runner->loadCases(config('fraud.eval_cases_path')))->keyBy('case_id');
        $candidates = [];

        foreach ($run['results'] as $result) {
            $row = $rows[$result['case_id']] ?? null;

            if ($row === null) {
                continue;
            }

            $candidates[$result['case_id']] = [
                'case_id' => $result['case_id'],
                'kind' => $result['my_label'] === $result['ai_label'] ? AdjudicationCase::CONTROL : AdjudicationCase::DISPUTED,
                'split' => $result['split'],
                'my_label' => $result['my_label'],
                'ai_label' => $result['ai_label'],
                'tags' => $result['tags'] ?? [],
                'facts' => array_map(fn ($key) => $row[$key], array_combine(array_keys(AdjudicationCase::FACTS), array_keys(AdjudicationCase::FACTS))),
            ];
        }

        return $candidates;
    }

    /**
     * A new draft version. With no earlier set: every disputed case plus as
     * many random controls. Otherwise it starts from the current set's cases,
     * with facts and labels re-read from the latest run.
     */
    public function newDraft(): AdjudicationSet
    {
        if ($draft = AdjudicationSet::draft()) {
            return $draft;
        }

        $candidates = $this->candidates();

        if ($candidates === []) {
            throw new InvalidArgumentException('There are no eval results yet. Run php artisan fraud:eval first.');
        }

        $current = AdjudicationSet::current();
        $ids = $current
            ? $current->cases()->pluck('case_id')->intersect(array_keys($candidates))->all()
            : $this->defaultSelection($candidates);

        $run = $this->report->latest();

        return DB::transaction(function () use ($ids, $candidates, $run) {
            $set = AdjudicationSet::create([
                'version' => (int) AdjudicationSet::max('version') + 1,
                // When the eval run these labels and AI calls come from was made.
                'source_run' => $run['run_at'] ?? null,
                'prompt_version' => $run['prompt_version'] ?? null,
            ]);

            foreach ($ids as $id) {
                $set->cases()->create($candidates[$id]);
            }

            return $set;
        });
    }

    /** Adds one case from the latest run to a draft. */
    public function add(AdjudicationSet $set, string $caseId): AdjudicationCase
    {
        $candidate = $this->candidates()[$caseId] ?? throw new InvalidArgumentException("{$caseId} is not in the latest eval run.");

        if ($set->cases()->where('case_id', $caseId)->exists()) {
            throw new InvalidArgumentException("{$caseId} is already in the set.");
        }

        return $set->cases()->create($candidate);
    }

    /** Removes a case from a draft. The model refuses if the set is published. */
    public function remove(AdjudicationCase $case): void
    {
        $case->delete();
    }

    /** Publishes a draft: it becomes the set reviewers vote on, and any older open set closes. */
    public function publish(AdjudicationSet $set): void
    {
        if (! $set->isDraft()) {
            throw new InvalidArgumentException('Only a draft can be published.');
        }
        if (! $set->cases()->where('kind', AdjudicationCase::DISPUTED)->exists()
            || ! $set->cases()->where('kind', AdjudicationCase::CONTROL)->exists()) {
            throw new InvalidArgumentException('A set needs at least one disputed case and one control case.');
        }

        DB::transaction(function () use ($set) {
            AdjudicationSet::where('status', AdjudicationSet::PUBLISHED)
                ->update(['status' => AdjudicationSet::CLOSED, 'closed_at' => now()]);
            $set->update(['status' => AdjudicationSet::PUBLISHED, 'published_at' => now()]);
        });
    }

    /** Ends voting. Per-case votes and reasons then appear on /eval. */
    public function close(AdjudicationSet $set): void
    {
        if (! $set->isOpen()) {
            throw new InvalidArgumentException('Only an open set can be closed.');
        }

        $set->update(['status' => AdjudicationSet::CLOSED, 'closed_at' => now()]);
    }

    /**
     * Every disputed case, plus the same number of agreed cases picked at
     * random as blind controls.
     *
     * @param  array<string, array<string, mixed>>  $candidates
     * @return list<string>
     */
    private function defaultSelection(array $candidates): array
    {
        $disputed = array_keys(array_filter($candidates, fn ($c) => $c['kind'] === AdjudicationCase::DISPUTED));
        $agreed = array_keys(array_filter($candidates, fn ($c) => $c['kind'] === AdjudicationCase::CONTROL));

        $controls = collect($agreed)->shuffle()->take(count($disputed))->all();

        return array_values(array_merge($disputed, $controls));
    }
}
