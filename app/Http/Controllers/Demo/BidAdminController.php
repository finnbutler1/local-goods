<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Models\Bid;
use App\Models\Redemption;
use Illuminate\View\View;

class BidAdminController extends Controller
{
    public function __invoke(): View
    {
        $redemptions = Redemption::with(['merchant', 'employee.employer'])
            ->latest()
            ->latest('id')
            ->take(25)
            ->get();

        return view('demo.bid-admin', [
            'bid' => Bid::firstOrFail(),
            'redemptions' => $redemptions,
            'heldCount' => Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->count(),
            'scoredCount' => Redemption::where('scored_by', '!=', 'seed')->count(),
        ]);
    }
}
