<?php

namespace App\Http\Controllers\Demo;

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
     * No logins in the demo: the wallet always belongs to the one seeded employee.
     */
    private function employee(): Employee
    {
        return Employee::with('employer')->firstOrFail();
    }

    public function show(Request $request): View
    {
        $employee = $this->employee();
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

        return view('demo.employee', [
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
            $this->employee(),
            Merchant::findOrFail($data['merchant_id']),
            (int) round(((float) $data['amount']) * 100),
        );

        return redirect()->route('demo.employee')->with('redemption_id', $redemption->id);
    }
}
