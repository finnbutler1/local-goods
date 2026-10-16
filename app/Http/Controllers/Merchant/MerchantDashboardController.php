<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\EmployerProgram;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What a merchant sees: Local Goods redemptions at their business, what they
 * are owed (cleared redemptions only; held ones are not owed until a person
 * clears them) and payouts, simulated until Stripe test mode. Customers are
 * shown by initials only (ADR 0014). A judge sees the demo merchant.
 */
class MerchantDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $merchant = $request->user()->isReadOnly()
            ? Merchant::where('name', config('demo.accounts.merchant.business'))->first() ?? Merchant::orderBy('id')->first()
            : $request->user()->merchant;

        if ($merchant === null) {
            return view('dashboards.merchant', ['merchant' => null]);
        }

        [$monthStart] = EmployerProgram::month();
        $redemptions = $merchant->redemptions();

        return view('dashboards.merchant', [
            'merchant' => $merchant,
            'owedCents' => $merchant->payableCents(),
            'heldCents' => (int) (clone $redemptions)->where('status', Redemption::STATUS_UNDER_REVIEW)->sum('amount_cents'),
            'heldCount' => (clone $redemptions)->where('status', Redemption::STATUS_UNDER_REVIEW)->count(),
            'monthCents' => (int) (clone $redemptions)->where('status', Redemption::STATUS_APPROVED)->where('created_at', '>=', $monthStart)->sum('amount_cents'),
            'monthVisits' => (clone $redemptions)->where('status', Redemption::STATUS_APPROVED)->where('created_at', '>=', $monthStart)->count(),
            'paidOutCents' => (int) $merchant->payouts()->sum('amount_cents'),
            'received' => (clone $redemptions)->with('employee')->latest()->latest('id')->take(25)->get(),
            'payouts' => $merchant->payouts()->latest('paid_at')->latest('id')->take(12)->get(),
        ]);
    }
}
