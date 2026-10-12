<?php

namespace App\Http\Controllers;

use App\Services\Fraud\FraudEvalReport;
use Illuminate\View\View;

/**
 * The public landing page. The AI trust section quotes the saved eval
 * headline, so the number on the page is always the one actually measured.
 */
class LandingController extends Controller
{
    public function __invoke(FraudEvalReport $report): View
    {
        return view('welcome', ['evalHeadline' => $report->latest()['headline'] ?? null]);
    }
}
