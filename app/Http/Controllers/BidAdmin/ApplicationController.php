<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Models\MerchantApplication;
use App\Services\Districts\MerchantApplications;
use App\Support\Districts\DistrictMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Merchant applications for the BID admin (ADR 0031): the queue, and for
 * each one the facts, where the address is on the district map, the AI's
 * suggestion and the decision. Run the eligibility check asks Merchant
 * Eligibility Matching (one AI call), so it is throttled. Only a BID or
 * platform admin approves or declines; judges see it read-only with the
 * contact email masked.
 */
class ApplicationController extends Controller
{
    public function index(): View
    {
        $applications = MerchantApplication::query()
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [MerchantApplication::ELIGIBILITY_CHECKED, MerchantApplication::APPLIED])
            ->latest('id')->get();

        return view('bid-admin.applications.index', [
            'applications' => $applications,
            'waiting' => $applications->whereIn('status', [MerchantApplication::APPLIED, MerchantApplication::ELIGIBILITY_CHECKED])->count(),
        ]);
    }

    public function show(MerchantApplication $application): View
    {
        $application->load(['events.actorUser', 'district', 'merchant']);
        $hasPoint = $application->latitude !== null;

        return view('bid-admin.applications.show', [
            'application' => $application,
            'map' => DistrictMap::for($application->district),
            'pins' => $hasPoint ? [['lat' => (float) $application->latitude, 'lng' => (float) $application->longitude,
                'label' => $application->business_name.' at '.$application->matched_address, 'kind' => 'applicant']] : [],
            'decide' => Gate::allows('decide-merchant-applications'),
            'seesEmail' => Gate::allows('decide-merchant-applications'),
        ]);
    }

    public function check(Request $request, MerchantApplication $application, MerchantApplications $applications): RedirectResponse
    {
        Gate::authorize('decide-merchant-applications');

        return $this->attempt($application, fn () => $applications->runCheck($application, $request->user()),
            'Eligibility check done for '.$application->business_name.'. The AI only suggests: approve or decline below.');
    }

    public function approve(Request $request, MerchantApplication $application, MerchantApplications $applications): RedirectResponse
    {
        Gate::authorize('decide-merchant-applications');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason'];

        return $this->attempt($application, fn () => $applications->approve($application, $request->user(), $reason),
            $application->business_name.' approved for the district. They can now sign in from their status link and set up Stripe payouts.');
    }

    public function decline(Request $request, MerchantApplication $application, MerchantApplications $applications): RedirectResponse
    {
        Gate::authorize('decide-merchant-applications');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason'];

        return $this->attempt($application, fn () => $applications->decline($application, $request->user(), $reason),
            $application->business_name.' declined. The applicant sees your reason on their status page.');
    }

    private function attempt(MerchantApplication $application, callable $step, string $done): RedirectResponse
    {
        $back = redirect()->route('bid-admin.applications.show', $application);

        try {
            $step();
        } catch (InvalidArgumentException $e) {
            return $back->with('error', $e->getMessage());
        }

        return $back->with('status', $done);
    }
}
