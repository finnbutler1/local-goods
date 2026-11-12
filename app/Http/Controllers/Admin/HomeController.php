<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FeedbackStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Models\SolvencyCheckRun;
use App\Services\DemoActivity\ActivityStatus;
use App\Support\PublicJudgeLink;
use App\Support\RecentActivity;
use App\Support\ShowMore;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Home for the platform admin (/admin) and the BID admin (/bid-admin/home;
 * judges see it as the BID admin, read-only). Each card is a short status
 * from stored data and links to its page. Nothing here calls Stripe or the
 * AI, and the reconciliation card never runs a reconciliation.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, ActivityStatus $activity, PublicJudgeLink $publicJudgeLink): View
    {
        $platform = $request->user()->role === Role::PlatformAdmin;
        $summary = $activity->summary();

        return view('dashboards.admin-home', [
            'platform' => $platform,
            'run' => SolvencyCheckRun::latest(),
            'activity' => $summary,
            'recent' => RecentActivity::for($platform, ShowMore::CAP),
            'unreadFeedback' => $platform ? Feedback::where('status', FeedbackStatus::New)->count() : 0,
            'publicJudgeUrl' => $platform ? $publicJudgeLink->url() : null,
        ]);
    }
}
