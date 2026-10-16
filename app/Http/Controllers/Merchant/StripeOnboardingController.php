<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\Payments\MerchantPayouts;
use App\Services\Payments\StripeGatewayException;
use App\Services\Payments\StripeOnboarding;
use App\Support\Money;
use App\Support\StripeMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A merchant connects their business to Stripe (Express, test mode) so
 * Stripe-funded cleared redemptions can be transferred to them (ADR 0015).
 * Only the merchant's own login can start or check onboarding; judges see
 * the status but never a button that onboards. GET pages never ask Stripe
 * to create anything.
 */
class StripeOnboardingController extends Controller
{
    private function ownMerchant(Request $request): Merchant
    {
        abort_if($request->user()->isReadOnly() || ! StripeMode::active(), 403);

        return $request->user()->merchant ?? abort(404);
    }

    public function start(Request $request, StripeOnboarding $onboarding): RedirectResponse
    {
        $merchant = $this->ownMerchant($request);

        if ($merchant->isStripeOnboarded()) {
            return redirect()->to(route('merchant.dashboard').'#stripe')->with('status', 'Stripe onboarding is already complete.');
        }

        try {
            return redirect()->away($onboarding->link($merchant));
        } catch (StripeGatewayException $e) {
            return redirect()->to(route('merchant.dashboard').'#stripe')->with('error', $e->getMessage());
        }
    }

    /** Stripe sends the merchant here when an onboarding link expired. Shows a button; creates nothing. */
    public function refresh(Request $request): View
    {
        return view('merchant.stripe-refresh', [
            'canOnboard' => ! $request->user()->isReadOnly() && StripeMode::active() && $request->user()->merchant !== null,
        ]);
    }

    /** Stripe sends the merchant back here after the form. Asks Stripe how it went. */
    public function return(Request $request, StripeOnboarding $onboarding, MerchantPayouts $payouts): RedirectResponse
    {
        if ($request->user()->isReadOnly() || ! StripeMode::active() || $request->user()->merchant === null) {
            return redirect()->route('merchant.dashboard');
        }

        return $this->check($request->user()->merchant, $onboarding, $payouts);
    }

    /** "Check Stripe status": the same check, for when the webhook hasn't arrived. */
    public function sync(Request $request, StripeOnboarding $onboarding, MerchantPayouts $payouts): RedirectResponse
    {
        return $this->check($this->ownMerchant($request), $onboarding, $payouts);
    }

    private function check(Merchant $merchant, StripeOnboarding $onboarding, MerchantPayouts $payouts): RedirectResponse
    {
        try {
            $onboarding->refresh($merchant);
        } catch (StripeGatewayException $e) {
            return redirect()->to(route('merchant.dashboard').'#stripe')->with('error', $e->getMessage());
        }

        if (! $merchant->isStripeOnboarded()) {
            return redirect()->to(route('merchant.dashboard').'#stripe')->with('status', $merchant->stripeOnboardingLabel().'. Money owed through Stripe waits until it is complete.');
        }

        $owed = $merchant->owedViaStripeCents();
        $result = $payouts->send($merchant);

        return redirect()->to(route('merchant.dashboard').'#stripe')->with('status', 'Stripe onboarding complete.'
            .($result['sent'] > 0 ? ' '.$result['sent'].' Stripe test-mode '.str('transfer')->plural($result['sent']).' sent ('.Money::format($owed - $merchant->owedViaStripeCents()).').' : '')
            .($result['failed'] > 0 ? ' '.$result['failed'].' could not be sent yet and stay owed.' : ''));
    }
}
