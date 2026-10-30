<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Models\SolvencyCheckRun;
use App\Services\Solvency\SolvencyCheck;
use App\Support\MerchantFee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Where the money is (ADR 0033): the two tranches, customer funds held and
 * operating funds, and the solvency check. For signed-in reviewers, the
 * BID admin and the platform admin. Judges count as the public for money
 * Local Goods earns: they see customer funds and the result of the check,
 * never an operating funds or fee figure. Only the platform admin can run
 * the check by hand; it reads the ledger and stores the result, nothing else.
 */
class WhereTheMoneyIsController extends Controller
{
    public function show(Request $request, SolvencyCheck $check): View
    {
        return view('money.where', [
            'now' => $check->now(),
            'run' => SolvencyCheckRun::latest(),
            'showsFigures' => MerchantFee::showsFiguresTo($request->user()),
            'mayRun' => $request->user()->can('run-solvency-check'),
        ]);
    }

    public function run(SolvencyCheck $check): RedirectResponse
    {
        $run = SolvencyCheckRun::record($history = $check->history(), SolvencyCheckRun::TRIGGER_ADMIN);
        $checked = number_format($run->entries_checked).' ledger '.($run->entries_checked === 1 ? 'entry' : 'entries').' at '.number_format($run->moments_checked).' moments';

        return redirect()->to(route('money').'#solvency-check')->with($run->passed ? 'status' : 'error', $run->passed
            ? 'Solvency check passed: '.$checked.', solvent at every moment.'
            : 'Solvency check failed: '.$history->summary());
    }
}
