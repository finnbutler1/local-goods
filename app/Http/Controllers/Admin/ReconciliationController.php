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
use Illuminate\Support\Str;
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
    public function index(StripeReconciliation $reconciliation): View
    {
        return view('admin.reconciliation', [
            'report' => $reconciliation->report(),
            'demoMerchants' => DemoMerchantStripeSetup::demoMerchants(),
        ]);
    }

    public function connectDemoMerchants(Request $request, DemoMerchantStripeSetup $setup): RedirectResponse
    {
        $back = redirect()->to(route('admin.reconciliation').'#demo-merchants');

        try {
            $result = $setup->run((string) $request->ip());
        } catch (StripeGatewayException $e) {
            return $back->with('error', $e->getMessage());
        }

        $sentence = 'Connected '.count($result['connected']).' demo '.Str::plural('merchant', count($result['connected'])).' to Stripe (test mode); '
            .count($result['already']).' already connected.'
            .($result['self_onboarding'] !== [] ? ' '.implode(', ', $result['self_onboarding']).' '.(count($result['self_onboarding']) === 1 ? 'has a login and onboards itself' : 'have logins and onboard themselves').'.' : '');

        if ($result['failed'] !== []) {
            return $back->with('error', $sentence.' Stripe refused '.implode('; ', array_map(fn ($name, $why) => $name.': '.$why, array_keys($result['failed']), $result['failed'])).' Press again to retry.');
        }

        return $back->with('status', $sentence);
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
