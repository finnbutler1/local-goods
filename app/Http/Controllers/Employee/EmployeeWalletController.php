<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\RedemptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeeWalletController extends Controller
{
    /**
     * The signed-in employee's own wallet. A login with no wallet linked yet
     * (for example before the demo data is seeded) gets 403.
     */
    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee()->with('employer')->first();

        abort_if($employee === null, 403, 'No wallet is linked to this login yet.');

        return $employee;
    }

    public function show(Request $request): View
    {
        $employee = $this->employee($request);
        $balance = $employee->balanceCents();
        $recent = $employee->redemptions()->with('merchant')->latest()->latest('id')->take(8)->get();

        $prefill = ['merchant_id' => null, 'amount' => null];
        $suspicious = $request->boolean('suspicious') && $balance > 0;

        if ($suspicious) {
            // Full balance, as a whole-dollar amount, at the merchant visited most recently.
            $prefill = [
                'merchant_id' => $recent->first()?->merchant_id,
                'amount' => (string) intdiv($balance, 100),
            ];
        }

        return view('employee.wallet', [
            'employee' => $employee,
            'balance' => $balance,
            'merchants' => Merchant::orderBy('name')->get(),
            'recent' => $recent,
            'prefill' => $prefill,
            'suspicious' => $suspicious,
            'result' => Redemption::with('merchant')->find($request->session()->get('redemption_id')),
        ]);
    }

    public function redeem(Request $request, RedemptionService $service): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
            'amount' => ['required', 'regex:/^\d{1,4}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
        ], [
            'amount.regex' => 'Enter an amount in dollars, like 12.50.',
            'amount.not_regex' => 'Enter an amount greater than zero.',
        ]);

        $redemption = $service->redeem(
            $this->employee($request),
            Merchant::findOrFail($data['merchant_id']),
            (int) round(((float) $data['amount']) * 100),
        );

        return redirect()->route('employee.wallet')->with('redemption_id', $redemption->id);
    }
}
