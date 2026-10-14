<?php

namespace App\Http\Controllers;

use App\Services\Fraud\FraudEvalLock;
use App\Services\Fraud\FraudEvalReport;
use Illuminate\View\View;

/**
 * Shows the latest Redemption Fraud Detection eval: how often the AI agreed
 * with hand-labeled cases. The numbers come from `php artisan fraud:eval`.
 * Public at /eval (the landing page links to it) and inside the BID admin's
 * app shell at /bid-admin/eval.
 */
class FraudEvalController extends Controller
{
    public function public(FraudEvalReport $report): View
    {
        return view('eval', ['run' => $report->latest(), 'lock' => FraudEvalLock::load()]);
    }

    public function admin(FraudEvalReport $report): View
    {
        return view('bid-admin.eval', ['run' => $report->latest(), 'lock' => FraudEvalLock::load()]);
    }
}
