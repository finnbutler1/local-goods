<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Services\DemoResetter;
use Illuminate\Http\RedirectResponse;

class ResetDemoController extends Controller
{
    public function __invoke(DemoResetter $resetter): RedirectResponse
    {
        $resetter->reset();

        return redirect()->route('bid-admin.dashboard')->with('status', 'Demo data has been reset to the seeded starting point.');
    }
}
