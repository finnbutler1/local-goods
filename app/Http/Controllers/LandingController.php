<?php

namespace App\Http\Controllers;

use App\Services\BidImpactMetrics;
use App\Services\Fraud\FraudEvalReport;
use App\Support\PublicJudgeLink;
use Illuminate\View\View;

/**
 * The public landing page. The AI trust section quotes the saved eval
 * headline, so the number on the page is always the one actually measured.
 * The impact strip shows the demo's ledger totals since launch: totals
 * only, never a merchant or employee name (ADR 0017).
 */
class LandingController extends Controller
{
    public function __invoke(FraudEvalReport $report, BidImpactMetrics $impact, PublicJudgeLink $publicJudgeLink): View
    {
        return view('welcome', [
            'evalHeadline' => $report->latest()['headline'] ?? null,
            'demoTotals' => $impact->totals(),
            // Completed refunds come off the spending total (ADR 0028); the label says so when there are any.
            'demoRefunded' => BidImpactMetrics::refundedCents() > 0,
            // Logged-out visitors only: nobody signed in ever gets the link from this page.
            'publicJudgeUrl' => auth()->guest() ? $publicJudgeLink->url() : null,
        ]);
    }
}
