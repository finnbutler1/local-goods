<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\RedemptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EmployeeWalletController extends Controller
{
    /**
     * The signed-in employee's own wallet. A login with no wallet linked yet
     * (for example before the demo data is seeded) gets 403. A judge sees
     * the demo employee's wallet, read-only.
     */
    private function employee(Request $request): Employee
    {
        $employee = $request->user()->isReadOnly()
            ? $this->demoEmployee()
            : $request->user()->employee()->with('employer')->first();

        abort_if($employee === null, 403, 'No wallet is linked to this login yet.');

        return $employee;
    }

    /** What a judge sees when viewing as an employee: the demo employee's wallet (all synthetic). */
    private function demoEmployee(): ?Employee
    {
        return Employee::with('employer')
            ->whereHas('user', fn ($q) => $q->where('email', config('demo.accounts.employee.email')))
            ->first() ?? Employee::with('employer')->orderBy('id')->first();
    }

    public function show(Request $request): View
    {
        $employee = $this->employee($request);
        $balance = $employee->balanceCents();
        $recent = $employee->redemptions()->with('merchant')->latest()->latest('id')->take(8)->get();

        // "Redeem here" on a merchant card picks that merchant in the form.
        $prefill = ['merchant_id' => $request->integer('merchant') ?: null, 'amount' => null];
        $suspicious = $request->boolean('suspicious') && $balance > 0;

        if ($suspicious) {
            // Full balance, as a whole-dollar amount, at the merchant visited most recently.
            $prefill = [
                'merchant_id' => $recent->first()?->merchant_id,
                'amount' => (string) intdiv($balance, 100),
            ];
        }

        $merchants = Merchant::orderBy('name')->get();
        $search = trim((string) $request->query('q', ''));
        $category = (string) $request->query('category', '');
        $browse = $merchants
            ->when($category !== '', fn ($list) => $list->where('category', $category))
            ->when($search !== '', fn ($list) => $list->filter(fn (Merchant $m) => str_contains(mb_strtolower($m->name.' '.$m->category.' '.$m->address), mb_strtolower($search))))
            ->values();

        // Newest first, each with the balance right after it (from the ledger).
        $showAll = $request->boolean('history');
        $entries = $employee->ledgerEntries()->latest('created_at')->latest('id')->when(! $showAll, fn ($q) => $q->take(20))->get();
        $running = $balance;
        $history = $entries->map(function ($entry) use (&$running) {
            $row = ['entry' => $entry, 'balance_after' => $running];
            $running -= $entry->amount_cents;

            return $row;
        });

        return view('employee.wallet', [
            'employee' => $employee,
            'balance' => $balance,
            'merchants' => $merchants,
            'categories' => $merchants->pluck('category')->unique()->sort()->values(),
            'browse' => $browse,
            'search' => $search,
            'category' => $category,
            'history' => $history,
            'historyCount' => $employee->ledgerEntries()->count(),
            'showAllHistory' => $showAll,
            'recent' => $recent,
            'prefill' => $prefill,
            'suspicious' => $suspicious,
            'result' => Redemption::with('merchant')->find($request->session()->get('redemption_id')),
        ]);
    }

    public function redeem(Request $request, RedemptionService $service): RedirectResponse
    {
        Gate::authorize('redeem');

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
