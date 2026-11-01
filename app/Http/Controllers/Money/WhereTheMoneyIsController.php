<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Models\OperatingSweep;
use App\Models\SolvencyCheckRun;
use App\Services\Payments\OperatingSweeps;
use App\Services\Solvency\SolvencyCheck;
use App\Services\Solvency\SolvencyRefused;
use App\Support\MerchantFee;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Where the money is (ADR 0033): the two tranches, customer funds held and
 * operating funds, and the solvency check. For signed-in reviewers, the
 * BID admin and the platform admin. Judges count as the public for money
 * Local Goods earns: they see customer funds and the result of the check,
 * never an operating funds or fee figure. Only the platform admin can run
 * the check by hand; it reads the ledger and stores the result, nothing else.
 *
 * The platform admin also sweeps money Local Goods has earned to Local
 * Goods Operating here (ADR 0034), in two steps: review writes nothing,
 * then confirm against the amount that was reviewed.
 */
class WhereTheMoneyIsController extends Controller
{
    public function show(Request $request, SolvencyCheck $check, OperatingSweeps $sweeps): View
    {
        return $this->page($request, $check, $sweeps);
    }

    public function run(SolvencyCheck $check): RedirectResponse
    {
        $run = SolvencyCheckRun::record($history = $check->history(), SolvencyCheckRun::TRIGGER_ADMIN);
        $checked = number_format($run->entries_checked).' ledger '.($run->entries_checked === 1 ? 'entry' : 'entries').' at '.number_format($run->moments_checked).' moments';

        return redirect()->to(route('money').'#solvency-check')->with($run->passed ? 'status' : 'error', $run->passed
            ? 'Solvency check passed: '.$checked.', solvent at every moment.'
            : 'Solvency check failed: '.$history->summary());
    }

    /** Step 1: what would be swept, and from where to where. Writes nothing. */
    public function reviewSweep(Request $request, SolvencyCheck $check, OperatingSweeps $sweeps): View
    {
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['amount.regex' => 'Enter dollars like 0.16.']);

        $cents = Money::toCents($data['amount']);
        $sweepable = $sweeps->sweepable();
        if ($cents < 1 || $cents > $sweepable) {
            throw ValidationException::withMessages(['amount' => 'You can sweep between $0.01 and '.Money::format($sweepable).'.'])
                ->redirectTo(route('money').'#sweep');
        }

        return $this->page($request, $check, $sweeps, ['cents' => $cents, 'reason' => trim($data['reason']), 'reviewed' => $sweepable]);
    }

    /** Step 2: the confirmed sweep, against the amount that was reviewed. */
    public function sweep(Request $request, OperatingSweeps $sweeps): RedirectResponse
    {
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'reviewed_against_cents' => ['required', 'integer', 'min:0'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Tick the box to confirm the sweep.']);

        try {
            $sweep = $sweeps->sweep($request->user(), (int) $data['amount_cents'], $data['reason'], (int) $data['reviewed_against_cents']);
        } catch (InvalidArgumentException|SolvencyRefused $e) {
            return redirect()->to(route('money').'#sweep')->with('error', $e->getMessage());
        }

        $amount = Money::format($sweep->amount_cents);

        return redirect()->to(route('money').'#sweep')->with(...match ($sweep->status) {
            OperatingSweep::STATUS_SENT => ['status', 'Swept '.$amount.' to Local Goods Operating: Stripe test-mode transfer '.$sweep->stripe_transfer_id.'.'],
            OperatingSweep::STATUS_SIMULATED => ['status', 'Swept '.$amount.' to Local Goods Operating (simulated, no real money).'],
            OperatingSweep::STATUS_FAILED => ['error', 'Stripe refused the sweep of '.$amount.', so nothing left the customer funds account: '.$sweep->failure_reason],
            default => ['notice', 'The sweep of '.$amount.' is reserved, but Stripe has not confirmed it yet. Retry it on Reconciliation; it can never be sent twice.'],
        });
    }

    /** @param  array{cents: int, reason: string, reviewed: int}|null  $review */
    private function page(Request $request, SolvencyCheck $check, OperatingSweeps $sweeps, ?array $review = null): View
    {
        return view('money.where', [
            'now' => $check->now(),
            'run' => SolvencyCheckRun::latest(),
            'showsFigures' => MerchantFee::showsFiguresTo($request->user()),
            'mayRun' => $request->user()->can('run-solvency-check'),
            'maySweep' => $request->user()->can('sweep-operating-funds'),
            'sweepable' => $sweeps->sweepable(),
            'sweepChannel' => OperatingSweeps::channel(),
            'sweepReview' => $review,
        ]);
    }
}
