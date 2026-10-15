<?php

namespace App\Http\Controllers;

use App\Services\Eligibility\MerchantEligibilityMatcher;
use App\Services\Eligibility\MerchantEvalReport;
use App\Services\Eligibility\MerchantLabelLock;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Merchant Eligibility Matching eval at /eval/merchants. Public, but
 * logged-out visitors and the shared public judge account see totals only:
 * the cases are real businesses, so names, notes, evidence and per-case
 * tables are shown only to named reviewers and admins
 * (User::maySeeCandidateNames).
 */
class MerchantEvalController extends Controller
{
    public function __invoke(Request $request, MerchantEvalReport $report): View
    {
        return view('eval-merchants', [
            'latest' => $report->latest(),
            'lock' => MerchantLabelLock::load(),
            'showNames' => (bool) $request->user()?->maySeeCandidateNames(),
            'promptVersion' => MerchantEligibilityMatcher::promptVersion(),
        ]);
    }
}
