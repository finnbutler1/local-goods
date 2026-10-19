<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Models\Bid;
use App\Services\BidImpactMetrics;
use Illuminate\View\View;

/**
 * The BID impact page: what the program did for the district, from the
 * ledger only, rolled up from the same numbers as each employer/HR dashboard
 * (ADR 0017). Employees are counted, never named. Judges can read it.
 */
class ImpactController extends Controller
{
    public function __invoke(BidImpactMetrics $metrics): View
    {
        return view('bid-admin.impact', [
            'bid' => Bid::first(),
            'impact' => $metrics->for(),
            'source' => config('impact.recirculation'),
        ]);
    }
}
