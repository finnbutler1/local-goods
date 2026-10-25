<?php

namespace App\Http\Controllers\Merchant;

use App\Exceptions\RedemptionAlreadyReviewed;
use App\Http\Controllers\Controller;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Models\RedemptionDecision;
use App\Services\PayCodes;
use App\Services\RedemptionReviewService;
use App\Support\MerchantFee;
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

    public const FEE_CHANGED = 'The Local Goods fee changed since you looked this code up. Check the amounts again, then confirm.';

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

        // The merchant is charged the Local Goods fee they were shown (ADR 0029). If it changed since, they look again first.
        $fee = MerchantFee::current();
        if ($fee->changeId !== (int) $request->input('fee_change', 0)) {
            throw ValidationException::withMessages(['pay_code' => self::FEE_CHANGED])->redirectTo($this->back());
        }

        $request->session()->forget(self::SESSION_KEY);

        $redemption = $codes->confirm($payCode, $merchant, $fee);

        $redemption->load(['employee', 'merchant', 'items']);
        $for = $redemption->itemsSummary();
        $what = Money::format($redemption->amount_cents).($for ? " ({$for})" : '')." from {$redemption->employee->initials()} at {$merchant->name}";

        return redirect()->to($this->back())->with(self::RESULT_KEY, $redemption->id)->with(...($redemption->isApproved()
            ? ['status', "Payment confirmed: {$what}. ".PayoutOutcome::sentence($redemption)]
            : ['notice', "Payment not completed yet: {$what}. It's being held for a quick review, and no money moves until a BID admin decides. If they pay another way, press Cancel this payment; otherwise wait for the review."]));
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

    /**
     * "Cancel this payment": a held in-person payment here, because the
     * customer paid another way (ADR 0026). The credits go back to them
     * through the same path as a "no purchase happened" denial; no transfer
     * ever, and it can't be approved later.
     */
    public function cancelHeld(Request $request, Redemption $redemption, RedemptionReviewService $reviews): RedirectResponse
    {
        Gate::authorize('take-payments');
        abort_unless(RedemptionDecision::mayCancel($request->user(), $redemption), 404);

        try {
            $reviews->cancel($redemption, $request->user());
        } catch (RedemptionAlreadyReviewed) {
            return redirect()->to($this->back())->with('error', 'Nothing changed: this payment was already decided.');
        }

        return redirect()->to($this->back())->with('status', 'Payment cancelled: '.Money::format($redemption->amount_cents)
            ." back to {$redemption->employee->initials()}'s credits at {$redemption->merchant->name}. You aren't owed it.");
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
