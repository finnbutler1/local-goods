<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdjudicationCase;
use App\Models\AdjudicationSet;
use App\Services\Adjudication\AdjudicationResults;
use App\Services\Adjudication\AdjudicationSetBuilder;
use App\Services\Fraud\FraudEvalRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Platform admin only: build, publish and close the blind adjudication set
 * for the fraud eval (ADR 0011), and read every adjudicator's votes.
 */
class AdjudicationController extends Controller
{
    public function __construct(private AdjudicationSetBuilder $builder) {}

    public function index(AdjudicationResults $results): View
    {
        Gate::authorize('manage-adjudication');

        $draft = AdjudicationSet::draft();
        $current = AdjudicationSet::current();
        $inDraft = $draft ? $draft->cases()->pluck('case_id')->all() : [];

        return view('admin.adjudication', [
            'draft' => $draft,
            'draftCases' => $draft ? $draft->cases()->orderBy('case_id')->get() : collect(),
            'current' => $current,
            'addable' => $draft ? array_diff_key($this->builder->candidates(), array_flip($inDraft)) : [],
            'splits' => FraudEvalRunner::SPLITS,
            // Admin sees names and every case's votes, even while voting is open.
            'adjudication' => $current ? $results->for($current) : null,
        ]);
    }

    /** Starts a draft: the first set, or a new version of the current one. */
    public function store(): RedirectResponse
    {
        Gate::authorize('manage-adjudication');

        return $this->attempt(fn () => $this->builder->newDraft(), 'Draft ready. Add or remove cases, then publish.');
    }

    public function addCase(Request $request): RedirectResponse
    {
        Gate::authorize('manage-adjudication');
        $data = $request->validate(['case_id' => ['required', 'string', 'max:16']]);
        $draft = AdjudicationSet::draft() ?? abort(404);

        return $this->attempt(fn () => $this->builder->add($draft, $data['case_id']), "Added {$data['case_id']}.");
    }

    public function removeCase(AdjudicationCase $adjudicationCase): RedirectResponse
    {
        Gate::authorize('manage-adjudication');
        abort_unless($adjudicationCase->set->isDraft(), 409, 'This set is published and locked. Start a new version to change it.');

        $this->builder->remove($adjudicationCase);

        return back()->with('status', "Removed {$adjudicationCase->case_id}.");
    }

    public function publish(): RedirectResponse
    {
        Gate::authorize('manage-adjudication');
        $draft = AdjudicationSet::draft() ?? abort(404);

        return $this->attempt(fn () => $this->builder->publish($draft), "Version {$draft->version} is published and locked. Reviewers can start voting.");
    }

    public function close(): RedirectResponse
    {
        Gate::authorize('manage-adjudication');
        $current = AdjudicationSet::current();
        abort_unless($current?->isOpen(), 404);

        return $this->attempt(fn () => $this->builder->close($current), 'Voting is closed. Every vote and reason now shows on /eval, without names.');
    }

    private function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['adjudication' => $e->getMessage()]);
        }

        return redirect()->route('admin.adjudication.index')->with('status', $message);
    }
}
