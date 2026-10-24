<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Services\PayCodes;
use App\Services\RedemptionBasket;
use App\Services\RedemptionService;
use App\Support\Money;
use App\Support\PayoutOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\MessageBag;
use Illuminate\View\View;

class EmployeeWalletController extends Controller
{
    use ResolvesEmployee;

    public function show(Request $request, PayCodes $codes): View
    {
        $employee = $this->employee($request);
        $balance = $employee->balanceCents();
        $recent = $employee->redemptions()->with(['merchant', 'decision', 'items'])->latest()->latest('id')->take(8)->get();

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
        // Step 2 of the redeem form: the chosen merchant's menu (ADR 0023). A form sent back with errors keeps its merchant.
        $menuMerchant = $merchants->firstWhere('id', (int) $request->old('merchant_id', $prefill['merchant_id']));
        $menu = $menuMerchant?->products()->available()->get() ?? collect();
        $search = trim((string) $request->query('q', ''));
        $category = (string) $request->query('category', '');
        $browse = $merchants
            ->when($category !== '', fn ($list) => $list->where('category', $category))
            ->when($search !== '', fn ($list) => $list->filter(fn (Merchant $m) => str_contains(mb_strtolower($m->name.' '.$m->category.' '.$m->address), mb_strtolower($search))))
            ->values();

        // Pay in person (ADR 0026): the live code, or how the one just shown ended.
        // Its form keeps its own old input (pay_merchant_id) and error bag, apart from Order to go.
        $payErrors = $request->session()->get('errors')?->getBag(PayCodeController::ERRORS);
        $liveCode = $codes->liveFor($employee)?->load('merchant');
        $payResult = $liveCode === null && $request->filled('pay_code')
            ? PayCode::with(['merchant', 'redemption.merchant', 'redemption.items', 'redemption.decision'])->where('employee_id', $employee->id)->find($request->integer('pay_code'))
            : null;
        $payMerchant = $merchants->firstWhere('id', (int) $request->old('pay_merchant_id', $request->integer('pay_merchant') ?: null));
        $tab = $request->query('tab') === 'in-person' || $liveCode || $payResult || $payMerchant || $payErrors?->any()
            ? 'in-person' : 'to-go';

        // Newest first, each with the balance right after it (from the ledger).
        $showAll = $request->boolean('history');
        $entries = $employee->ledgerEntries()->with('redemption')->latest('created_at')->latest('id')->when(! $showAll, fn ($q) => $q->take(20))->get();
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
            'menuMerchant' => $menuMerchant,
            'menu' => $menu,
            'suspicious' => $suspicious,
            'result' => Redemption::with(['merchant', 'items'])->find($request->session()->get('redemption_id'))
                ?? ($payResult?->redemption),
            'tab' => $tab,
            'liveCode' => $liveCode,
            'payResult' => $payResult,
            'payMerchant' => $payMerchant,
            'payMenu' => $payMerchant?->products()->available()->get() ?? collect(),
            'payErrors' => $payErrors ?? new MessageBag,
        ]);
    }

    public function redeem(Request $request, RedemptionService $service): RedirectResponse
    {
        Gate::authorize('redeem');

        $data = $request->validate([
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
            // Menu quantities, product id => how many (ADR 0023). The amount is the "Other amount" part.
            'items' => ['nullable', 'array', 'max:50'],
            'items.*' => ['nullable', 'integer', 'min:0', 'max:'.RedemptionBasket::MAX_QUANTITY],
            'amount' => ['nullable', 'regex:/^\d{1,4}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
        ], [
            'amount.regex' => 'Enter an amount in dollars, like 12.50.',
            'amount.not_regex' => 'Enter an amount greater than zero.',
            'items.*' => 'Quantities are whole numbers from 0 to '.RedemptionBasket::MAX_QUANTITY.'.',
        ]);

        $merchant = Merchant::findOrFail($data['merchant_id']);
        $basket = RedemptionBasket::build($merchant, $data['items'] ?? [], isset($data['amount']) ? Money::toCents($data['amount']) : 0);

        $redemption = $service->redeem($this->employee($request), $merchant, $basket['total_cents'], $basket['items']);

        $redemption->load(['merchant', 'items']);
        $for = $redemption->itemsSummary();
        $at = Money::format($redemption->amount_cents).($for ? " ({$for})" : '')." at {$redemption->merchant->name}";

        return redirect()->route('employee.wallet')->with('redemption_id', $redemption->id)->with(...($redemption->isApproved()
            ? ['status', "{$at} approved. ".PayoutOutcome::sentence($redemption)]
            : ['notice', "{$at} held for a quick review: no money moves until a BID admin decides."]));
    }
}
