<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\PayCode;
use App\Services\PayCodes;
use App\Services\RedemptionBasket;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The employee's side of paying in person (ADR 0026): get a code, cancel
 * it, and ask whether the merchant has confirmed it yet. Getting a code
 * moves nothing; the merchant's Confirm makes the redemption.
 */
class PayCodeController extends Controller
{
    use ResolvesEmployee;

    /** Errors from this form go in their own bag, so the Order to go form never shows them. */
    public const ERRORS = 'payCode';

    public function store(Request $request, PayCodes $codes): RedirectResponse
    {
        Gate::authorize('redeem');

        try {
            $data = $request->validate([
                'pay_merchant_id' => ['required', 'integer', 'exists:merchants,id'],
                'items' => ['nullable', 'array', 'max:50'],
                'items.*' => ['nullable', 'integer', 'min:0', 'max:'.RedemptionBasket::MAX_QUANTITY],
                'amount' => ['nullable', 'regex:/^\d{1,4}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            ], [
                'amount.regex' => 'Enter an amount in dollars, like 12.50.',
                'amount.not_regex' => 'Enter an amount greater than zero.',
                'items.*' => 'Quantities are whole numbers from 0 to '.RedemptionBasket::MAX_QUANTITY.'.',
            ]);

            $merchant = Merchant::findOrFail($data['pay_merchant_id']);
            $basket = RedemptionBasket::build($merchant, $data['items'] ?? [], isset($data['amount']) ? Money::toCents($data['amount']) : 0);
            $payCode = $codes->issue($this->employee($request), $merchant, $basket['total_cents'], $basket['items'], $basket['tax']);
        } catch (ValidationException $e) {
            throw $e->errorBag(self::ERRORS)->redirectTo(route('employee.wallet', ['tab' => 'in-person']).'#pay');
        }

        return redirect()->to(route('employee.wallet', ['tab' => 'in-person']).'#pay')
            ->with('status', 'Pay code ready for '.Money::format($payCode->amount_cents)." at {$merchant->name}. Show it to the cashier.");
    }

    public function cancel(Request $request, PayCode $payCode, PayCodes $codes): RedirectResponse
    {
        Gate::authorize('redeem');
        abort_unless($payCode->employee_id === $this->employee($request)->id, 404);

        $codes->cancelByEmployee($payCode);

        return redirect()->to(route('employee.wallet', ['tab' => 'in-person']).'#pay')
            ->with('status', 'Pay code cancelled. Nothing was charged.');
    }

    /**
     * Polled by the code screen every few seconds. The status only, never
     * the code. "confirming" while the merchant's Confirm is being checked.
     */
    public function status(Request $request, PayCode $payCode, PayCodes $codes): JsonResponse
    {
        abort_unless($payCode->employee_id === $this->employee($request)->id, 404);

        $codes->expireStale();
        $payCode->refresh();
        $status = $payCode->status === PayCode::STATUS_USED && $payCode->redemption_id === null ? 'confirming' : $payCode->status;

        return response()->json(['status' => $status]);
    }
}
