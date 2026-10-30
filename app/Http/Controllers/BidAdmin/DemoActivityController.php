<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Services\DemoActivity\ActivityStatus;
use Illuminate\View\View;

/**
 * What the demo activity generator has done (ADR 0032). Read-only for
 * everyone: the BID admin, the platform admin and judges (via View as).
 * Starting and stopping it is DEMO_ACTIVITY_ENABLED in Beanstalk, never a
 * button here.
 */
class DemoActivityController extends Controller
{
    public function __invoke(ActivityStatus $status): View
    {
        return view('bid-admin.demo-activity', ['s' => $status->summary()]);
    }
}
