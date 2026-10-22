<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\Payments\DemoMerchantStripeSetup;
use App\Services\Payments\MerchantPayouts;
use App\Services\Payments\StripeGatewayException;
use App\Services\Payments\StripeReconciliation;
use App\Support\StripeMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Platform admin: does the ledger agree with Stripe? Stripe-funded activity
 * only (ADR 0015). Opening the page only reads from Stripe; "Retry owed
 * transfers" sends Stripe-channel payouts that are still owed, and
 * "Connect demo merchants to Stripe" gives the fictional demo merchants with
 * no login a test account (DemoMerchantStripeSetup).
 */
class ReconciliationController extends Controller
{
    public function index(Request $request, StripeReconciliation $reconciliation, DemoMerchantStripeSetup $setup): View
    {
        return view('admin.reconciliation', [
            'report' => $reconciliation->report(),
            'demoMerchants' => DemoMerchantStripeSetup::demoMerchants(),
            'demoProgress' => $setup->progress(),
            // Set only by the redirect after a step that left work to do.
            'continueRound' => $request->has('connecting') ? $request->integer('connecting') : null,
        ]);
    }

    /**
     * One demo merchant per request (DemoMerchantStripeSetup::step), then
     * back to the page, which presses again by itself (data-auto-submit)
     * until all are done. Without JavaScript the person presses Continue.
     * `round` counts the automatic presses, so a merchant Stripe is slow to
     * confirm can never make the page loop forever.
     */
    public function connectDemoMerchants(Request $request, DemoMerchantStripeSetup $setup): RedirectResponse
    {
        try {
            $step = $setup->step((string) $request->ip());
        } catch (StripeGatewayException $e) {
            return redirect()->to(route('admin.reconciliation').'#demo-merchants')
                ->with('error', $e->getMessage().' Press Continue to try that merchant again; nothing is duplicated.');
        }

        if ($step['done'] < $step['total']) {
            $round = $request->integer('round') + 1;
            $auto = $step['merchant'] !== null && $round < 2 * $step['total'];

            return redirect()->to(route('admin.reconciliation', $auto ? ['connecting' => $round] : []).'#demo-merchants')
                ->with('notice', "{$step['done']} of {$step['total']} demo merchants connected".($auto ? ', continuing…' : '. Press Continue connecting to go on.'));
        }

        return redirect()->to(route('admin.reconciliation').'#demo-merchants')->with('status',
            "All {$step['total']} demo merchants are connected to Stripe (test mode). Stripe turns on their payouts within a minute or so; their status updates by itself.");
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
