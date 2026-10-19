<?php

namespace App\Http\Controllers;

use App\Services\BidImpactMetrics;
use App\Services\Fraud\FraudEvalReport;
use Illuminate\View\View;

/**
 * The public landing page. The AI trust section quotes the saved eval
 * headline, so the number on the page is always the one actually measured.
 * The impact strip shows the demo's ledger totals since launch: totals
 * only, never a merchant or employee name (ADR 0017).
 */
class LandingController extends Controller
{
    public function __invoke(FraudEvalReport $report, BidImpactMetrics $impact): View
    {
        return view('welcome', [
            'evalHeadline' => $report->latest()['headline'] ?? null,
            'demoTotals' => $impact->totals(),
        ]);
    }
}
