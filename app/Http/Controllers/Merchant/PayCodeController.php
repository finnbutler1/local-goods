<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\PayCode;
use App\Services\PayCodes;
use App\Support\Money;
use App\Support\PayoutOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The merchant's side of paying in person (ADR 0026): type the customer's
 * pay code, check the amount, then Confirm payment or Decline. Confirming
 * is an ordinary redemption through RedemptionService (Redemption Fraud
 * Detection, TransferGate, Stripe test transfer), marked "in person".
 *
 * After a good lookup only the pay code's id is kept in the session, never
 * the code, and Confirm or Decline only work on that same id.
 */
class PayCodeController extends Controller
{
    use ResolvesMerchant;

    /** The id of the pay code this merchant just looked up. */
    public const SESSION_KEY = 'merchant_pay_code_id';

    /** The redemption just confirmed, for the "done" card. Not a toast key. */
    public const RESULT_KEY = 'merchant_pay_result_id';

    public function lookup(Request $request, PayCodes $codes): RedirectResponse
    {
        Gate::authorize('take-payments');
        $merchant = $this->merchant($request);

        try {
            $data = $request->validate(['pay_code' => ['required', 'string', 'max:20']], ['pay_code.required' => 'Type the customer\'s pay code.']);
            $payCode = $codes->lookup($merchant, $data['pay_code']);
        } catch (ValidationException $e) {
            throw $e->redirectTo($this->back());
        }

        $request->session()->put(self::SESSION_KEY, $payCode->id);

        return redirect()->to($this->back());
    }

    public function confirm(Request $request, PayCode $payCode, PayCodes $codes): RedirectResponse
    {
        Gate::authorize('take-payments');
        $merchant = $this->merchant($request);
        $this->ensureLookedUp($request, $payCode);
        $request->session()->forget(self::SESSION_KEY);

        $redemption = $codes->confirm($payCode, $merchant);

        $redemption->load(['employee', 'merchant', 'items']);
        $for = $redemption->itemsSummary();
        $what = Money::format($redemption->amount_cents).($for ? " ({$for})" : '')." from {$redemption->employee->initials()} at {$merchant->name}";

        return redirect()->to($this->back())->with(self::RESULT_KEY, $redemption->id)->with(...($redemption->isApproved()
            ? ['status', "Payment confirmed: {$what}. ".PayoutOutcome::sentence($redemption)]
            : ['notice', "Payment not completed yet: {$what}. It's being held for a quick review, and no money moves until a BID admin decides. You can ask for another way to pay, or wait for the review."]));
    }

    public function decline(Request $request, PayCode $payCode, PayCodes $codes): RedirectResponse
    {
        Gate::authorize('take-payments');
        $merchant = $this->merchant($request);
        $this->ensureLookedUp($request, $payCode);
        $request->session()->forget(self::SESSION_KEY);

        $codes->decline($payCode, $merchant);

        return redirect()->to($this->back())
            ->with('status', 'Pay code declined: '.Money::format($payCode->amount_cents)." from {$payCode->employee->initials()} at {$merchant->name}. Nothing was charged.");
    }

    /** "Enter a different code": forget the one looked up. */
    public function clear(Request $request): RedirectResponse
    {
        $this->merchant($request);
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->to($this->back());
    }

    /** Only the code this merchant just looked up; anything else is the same "Code not valid." */
    private function ensureLookedUp(Request $request, PayCode $payCode): void
    {
        if ((int) $request->session()->get(self::SESSION_KEY) !== $payCode->id) {
            throw ValidationException::withMessages(['pay_code' => PayCodes::NOT_VALID])->redirectTo($this->back());
        }
    }

    private function back(): string
    {
        return route('merchant.dashboard').'#take-payment';
    }
}
