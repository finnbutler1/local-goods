<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Services\EmployerProgram;
use App\Services\MerchantTaxSummary;
use App\Services\PayCodes;
use App\Support\StripeMode;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What a merchant sees: Local Goods redemptions at their business, what they
 * are owed (cleared redemptions only; held ones are not owed until a person
 * clears them), split into owed through Stripe test mode and owed simulated
 * (ADR 0015), Stripe onboarding, payouts, and taking a payment in person
 * with the customer's pay code (ADR 0026), and the DC sales tax on cleared
 * menu orders for their records (ADR 0027). Customers are
 * shown by initials only (ADR 0014). A judge sees the demo merchant.
 */
class MerchantDashboardController extends Controller
{
    /** Redemptions listed before "Show all". */
    public const RECENT = 10;

    public function __invoke(Request $request): View
    {
        $merchant = $request->user()->isReadOnly()
            ? Merchant::where('name', config('demo.accounts.merchant.business'))->first() ?? Merchant::orderBy('id')->first()
            : $request->user()->merchant;

        if ($merchant === null) {
            return view('dashboards.merchant', ['merchant' => null]);
        }

        // Pay in person (ADR 0026): the code just looked up, while it's still live, or the payment just confirmed.
        app(PayCodes::class)->expireStale();
        $lookedUp = PayCode::with('employee')->where('merchant_id', $merchant->id)->where('status', PayCode::STATUS_ACTIVE)
            ->find($request->session()->get(PayCodeController::SESSION_KEY));

        [$monthStart] = EmployerProgram::month();
        $redemptions = $merchant->redemptions();

        return view('dashboards.merchant', [
            'merchant' => $merchant,
            'lookedUp' => $lookedUp,
            'payResult' => $merchant->redemptions()->with(['employee', 'items', 'decision'])->find($request->session()->get(PayCodeController::RESULT_KEY)),
            // Held in-person payments stay here with "Cancel this payment" until a BID admin decides (ADR 0026).
            'heldAtCounter' => (clone $redemptions)->with(['employee', 'items'])->where('channel', Redemption::CHANNEL_IN_PERSON)
                ->where('status', Redemption::STATUS_UNDER_REVIEW)->latest()->latest('id')->get(),
            'owedCents' => $merchant->payableCents(),
            'owedStripeCents' => $merchant->owedViaStripeCents(),
            'owedSimulatedCents' => $merchant->owedSimulatedCents(),
            'stripe' => StripeMode::active(),
            'canOnboard' => StripeMode::active() && ! $request->user()->isReadOnly(),
            'heldCents' => (int) (clone $redemptions)->where('status', Redemption::STATUS_UNDER_REVIEW)->sum('amount_cents'),
            'heldCount' => (clone $redemptions)->where('status', Redemption::STATUS_UNDER_REVIEW)->count(),
            'monthCents' => (int) (clone $redemptions)->where('status', Redemption::STATUS_APPROVED)->where('created_at', '>=', $monthStart)->sum('amount_cents'),
            'monthVisits' => (clone $redemptions)->where('status', Redemption::STATUS_APPROVED)->where('created_at', '>=', $monthStart)->count(),
            'paidOutCents' => (int) $merchant->payouts()->paid()->sum('amount_cents'),
            'taxSummary' => MerchantTaxSummary::for($merchant),
            'received' => (clone $redemptions)->with(['employee', 'decision', 'stripePayout', 'items', 'latestCorrection'])->latest()->latest('id')
                ->when(! $request->boolean('all'), fn ($q) => $q->take(self::RECENT))->get(),
            'receivedCount' => (clone $redemptions)->count(),
            'showAll' => $request->boolean('all'),
            // Stripe rows carry their redemption, so each shows its path (ADR 0016).
            'payouts' => $merchant->payouts()->with(['redemption.employee', 'redemption.decision', 'redemption.stripePayout', 'latestCorrection'])->latest('id')->take(12)->get(),
        ]);
    }
}
