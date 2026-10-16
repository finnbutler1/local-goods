<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\Payments\MerchantPayouts;
use App\Services\Payments\StripeReconciliation;
use App\Support\StripeMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Platform admin: does the ledger agree with Stripe? Stripe-funded activity
 * only (ADR 0015). Opening the page only reads from Stripe; "Retry owed
 * transfers" sends Stripe-channel payouts that are still owed.
 */
class ReconciliationController extends Controller
{
    public function index(StripeReconciliation $reconciliation): View
    {
        return view('admin.reconciliation', ['report' => $reconciliation->report()]);
    }

    public function retry(MerchantPayouts $payouts): RedirectResponse
    {
        if (! StripeMode::active()) {
            return redirect()->route('admin.reconciliation')->with('error', 'Stripe test mode is off on this server, so there is nothing to send.');
        }

        $totals = ['sent' => 0, 'failed' => 0, 'waiting' => 0];
        foreach (Merchant::orderBy('id')->get() as $merchant) {
            foreach ($payouts->send($merchant) as $key => $count) {
                $totals[$key] += $count;
            }
        }

        return redirect()->route('admin.reconciliation')->with('status',
            "Sent {$totals['sent']}. Still not sent: {$totals['failed']} refused or unreachable, {$totals['waiting']} waiting for onboarding.");
    }
}
