<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Services\Fraud\CollusionExample;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The admins' "Run a collusion example" button (ADR 0016). One real AI call
 * per press, rate-limited on the route. Judges are refused by the route's
 * role check, BlockJudgeWrites and this gate, and never see the button.
 */
class CollusionExampleController extends Controller
{
    public function __invoke(CollusionExample $example): RedirectResponse
    {
        Gate::authorize('run-collusion-example');

        try {
            $redemption = $example->run();
        } catch (InvalidArgumentException $e) {
            return redirect()->route('bid-admin.dashboard')->with('error', $e->getMessage());
        }

        return redirect()->to(route('bid-admin.dashboard').'#redemption-'.$redemption->id)
            ->with('collusion_example', $redemption->id);
    }
}
