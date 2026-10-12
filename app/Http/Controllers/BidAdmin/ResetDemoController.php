<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Services\DemoResetter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ResetDemoController extends Controller
{
    public function __invoke(DemoResetter $resetter): RedirectResponse
    {
        Gate::authorize('reset-demo');

        $resetter->reset();

        return redirect()->route('bid-admin.dashboard')->with('status', 'Demo data has been reset to the seeded starting point.');
    }
}
