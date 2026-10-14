<?php

namespace App\Http\Controllers;

use App\Models\AdjudicationSet;
use App\Services\Adjudication\AdjudicationResults;
use App\Services\Fraud\FraudEvalLock;
use App\Services\Fraud\FraudEvalReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Shows the latest Redemption Fraud Detection eval: how often the AI agreed
 * with hand-labeled cases. The numbers come from `php artisan fraud:eval`.
 * Public at /eval (the landing page links to it) and inside the BID admin's
 * app shell at /bid-admin/eval.
 */
class FraudEvalController extends Controller
{
    public function public(Request $request, FraudEvalReport $report, AdjudicationResults $results): View
    {
        return view('eval', $this->data($request, $report, $results));
    }

    public function admin(Request $request, FraudEvalReport $report, AdjudicationResults $results): View
    {
        return view('bid-admin.eval', $this->data($request, $report, $results));
    }

    /**
     * While blind adjudication is open (ADR 0011), a reviewer who may vote
     * but hasn't submitted sees neither the per-case disagreements (they
     * show Finn's label and the AI's call) nor the adjudication results.
     * Results appear once someone has submitted; each case's votes only
     * once voting closes.
     *
     * @return array<string, mixed>
     */
    private function data(Request $request, FraudEvalReport $report, AdjudicationResults $results): array
    {
        $hide = AdjudicationSet::hidesCaseDetailsFor($request->user());
        $set = AdjudicationSet::current();

        return [
            'run' => $report->latest(),
            'lock' => FraudEvalLock::load(),
            'hideCaseDetails' => $hide,
            'adjudicationSet' => $set,
            'adjudication' => $set && ! $hide ? $results->for($set) : null,
            'showNames' => false,
            'showCases' => $set?->isClosed() ?? false,
        ];
    }
}
