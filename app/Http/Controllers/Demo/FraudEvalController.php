<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Services\Fraud\FraudEvalReport;
use Illuminate\View\View;

/**
 * Shows the latest Redemption Fraud Detection eval: how often the AI agreed
 * with hand-labeled cases. The numbers come from `php artisan fraud:eval`.
 */
class FraudEvalController extends Controller
{
    public function __invoke(FraudEvalReport $report): View
    {
        return view('demo.fraud-eval', ['run' => $report->latest()]);
    }
}
