<?php

namespace App\Http\Controllers\Employer;

use App\Enums\Occasion;
use App\Http\Controllers\Controller;
use App\Models\CreditAllocation;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Services\CreditLedger;
use App\Services\EmployerMetrics;
use App\Services\EmployerProgram;
use App\Services\Payments\StripeCheckout;
use App\Services\Payments\StripeGatewayException;
use App\Support\Money;
use App\Support\StripeMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The employer/HR dashboard: program setup, buying credits (pending until
 * paid: by Stripe Checkout in test mode, or simulated when Stripe keys are
 * missing; ADR 0015), handing them to
 * employees, and how much the team got out into the neighborhood. The MVP
 * has one employer (ADR 0002). Judges see it read-only; employees are shown
 * by initials and staff number, never by name (ADR 0014).
 */
class EmployerDashboardController extends Controller
{
    /** Dollars with up to two decimals, like the wallet's amount field. */
    private const DOLLARS = 'regex:/^\d{1,6}(\.\d{1,2})?$/';

    private function employer(): ?Employer
    {
        return Employer::orderBy('id')->first();
    }

    public function show(Request $request, EmployerMetrics $metrics): View
    {
        $employer = $this->employer();

        if ($employer === null) {
            return view('dashboards.employer', ['employer' => null]);
        }

        $employees = $employer->employees()->orderBy('id')
            ->withSum('ledgerEntries as balance_cents', 'amount_cents')
            ->get();

        $this->checkoutMessage($request, $employer);

        return view('dashboards.employer', [
            'employer' => $employer,
            'stripe' => StripeMode::active(),
            'metrics' => $metrics->for($employer),
            'balance' => $employer->balanceCents(),
            'pendingCents' => EmployerProgram::pendingCents($employer),
            'allocatedThisMonth' => EmployerProgram::allocatedInMonthCents($employer),
            'remainingBudget' => EmployerProgram::remainingBudgetCents($employer),
            'purchases' => $employer->creditPurchases()->latest('purchased_at')->latest('id')->take(8)->get(),
            'returned' => self::returnedCredit($employer),
            'allocationGroups' => self::recentAllocationGroups($employer),
            'employees' => $employees,
        ]);
    }

    /**
     * Back from Stripe Checkout (?checkout=returned|cancelled&purchase=…): say
     * how it went in the page's toast. Only Stripe's signed webhook marks a
     * purchase paid, so "returned" alone may still be pending.
     */
    private function checkoutMessage(Request $request, Employer $employer): void
    {
        $checkout = $request->query('checkout');
        $purchase = in_array($checkout, ['returned', 'cancelled'], true)
            ? $employer->creditPurchases()->where('reference', (string) $request->query('purchase'))->first()
            : null;

        match (true) {
            $checkout === 'cancelled' => $request->session()->now('notice', 'Payment cancelled. Nothing was charged; the purchase is still pending.'),
            $purchase?->isPending() => $request->session()->now('notice', "Back from Stripe. {$purchase->reference} stays pending until Stripe confirms the payment to Local Goods, usually within a few seconds. Refresh this page to check."),
            (bool) $purchase?->isStripe() => $request->session()->now('status', Money::format($purchase->amount_cents)." in credits added (Stripe test mode, {$purchase->reference}). Ready to give."),
            default => null,
        };
    }

    /**
     * Credit that came back to the employer because a BID admin denied a held
     * redemption as suspected abuse (ADR 0016): the total, how many, and the
     * newest entries for the credits activity. Already part of "Ready to
     * give", since the balance is the sum of the employer's ledger entries.
     *
     * @return array{cents: int, count: int, entries: Collection<int, CreditLedgerEntry>}
     */
    public static function returnedCredit(Employer $employer, int $recent = 8): array
    {
        $query = fn () => $employer->ledgerEntries()->where('type', CreditLedgerEntry::TYPE_REVIEW_DENIED_TO_EMPLOYER);

        return [
            'cents' => (int) $query()->sum('amount_cents'),
            'count' => $query()->count(),
            'entries' => $query()->with(['redemption.employee', 'redemption.decision'])->latest('created_at')->latest('id')->take($recent)->get(),
        ];
    }

    /**
     * "Recently given", newest first. One bulk action gives everyone the same
     * occasion and amount at the same moment, so allocations sharing all
     * three are shown as one row that expands to the people in it.
     *
     * @return Collection<int, array{occasion: Occasion, amount_cents: int, at: Carbon, allocations: Collection<int, CreditAllocation>}>
     */
    public static function recentAllocationGroups(Employer $employer, int $groups = 8): Collection
    {
        return $employer->creditAllocations()->with('employee')
            ->latest('allocated_at')->latest('id')->take(500)->get()
            ->groupBy(fn (CreditAllocation $a) => $a->allocated_at->toIso8601String().'|'.($a->occasion ?? Occasion::Monthly)->value.'|'.$a->amount_cents)
            ->take($groups)
            ->map(fn (Collection $group) => [
                'occasion' => $group->first()->occasion ?? Occasion::Monthly,
                'amount_cents' => $group->first()->amount_cents,
                'at' => $group->first()->allocated_at,
                'allocations' => $group->sortBy('employee_id')->values(),
            ])->values();
    }

    public function updateProgram(Request $request): RedirectResponse
    {
        Gate::authorize('manage-employer-program');
        $employer = $this->employer() ?? abort(404);

        $rules = [
            'monthly_budget' => ['nullable', self::DOLLARS],
            'monthly_allowance' => ['required', self::DOLLARS, 'not_regex:/^0+(\.0+)?$/'],
        ];
        foreach (Occasion::optional() as $occasion) {
            $rules["occasions.{$occasion->value}.enabled"] = ['nullable', 'boolean'];
            $rules["occasions.{$occasion->value}.amount"] = ["required_if_accepted:occasions.{$occasion->value}.enabled", 'nullable', self::DOLLARS];
        }

        $data = $request->validate($rules, $this->messages() + [
            'occasions.*.amount.required_if_accepted' => 'Enter an amount, or switch this occasion off.',
        ]);

        $update = [
            'monthly_budget_cents' => filled($data['monthly_budget'] ?? null) ? Money::toCents($data['monthly_budget']) : null,
            'monthly_allowance_cents' => Money::toCents($data['monthly_allowance']),
        ];
        foreach (Occasion::optional() as $occasion) {
            $on = (bool) ($data['occasions'][$occasion->value]['enabled'] ?? false);
            $update[$occasion->column()] = $on ? Money::toCents($data['occasions'][$occasion->value]['amount']) : null;
        }

        $employer->update($update);

        return redirect()->to(route('employer.dashboard').'#program')->with('status', 'Program saved.');
    }

    public function storePurchase(Request $request, CreditLedger $ledger): RedirectResponse
    {
        Gate::authorize('manage-employer-program');
        $employer = $this->employer() ?? abort(404);

        $data = $request->validate([
            'purchase_amount' => ['required', self::DOLLARS, 'not_regex:/^0+(\.0+)?$/'],
        ], $this->messages());

        $purchase = $ledger->requestPurchase($employer, Money::toCents($data['purchase_amount']), EmployerProgram::nextReference($employer));

        return redirect()->to(route('employer.dashboard').'#credits')->with('status',
            'Purchase '.$purchase->reference.' of '.Money::format($purchase->amount_cents).' is pending. '
            .(StripeMode::active() ? 'Press "Pay with Stripe (test mode)" to unlock the credits.' : 'Press "Pay now" to unlock the credits.'));
    }

    /**
     * Stripe test mode: off to Stripe Checkout; the purchase stays pending
     * until the webhook confirms payment. Simulated mode (no Stripe keys):
     * marks it paid with no real money, as before.
     */
    public function payPurchase(Request $request, CreditPurchase $purchase, CreditLedger $ledger, StripeCheckout $checkout): RedirectResponse
    {
        Gate::authorize('manage-employer-program');
        abort_unless($purchase->employer_id === $this->employer()?->id, 404);

        if (StripeMode::active()) {
            try {
                return redirect()->away($checkout->start($purchase));
            } catch (InvalidArgumentException|StripeGatewayException $e) {
                return redirect()->to(route('employer.dashboard').'#credits')->with('error', "Payment didn't start: {$e->getMessage()} Nothing was charged; the purchase is still pending.");
            }
        }

        try {
            $ledger->payPurchase($purchase);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(route('employer.dashboard').'#credits')->with('error', "Nothing changed: {$e->getMessage()}");
        }

        return redirect()->to(route('employer.dashboard').'#credits')->with('status',
            Money::format($purchase->amount_cents).' paid (simulated, no real money). The credits are ready to give to your team.');
    }

    public function allocate(Request $request, CreditLedger $ledger): RedirectResponse
    {
        Gate::authorize('manage-employer-program');
        $employer = $this->employer() ?? abort(404);

        $data = $request->validate([
            'mode' => ['required', Rule::in(['individual', 'bulk'])],
            'occasion' => ['required', Rule::enum(Occasion::class)],
            'amount' => ['required', self::DOLLARS, 'not_regex:/^0+(\.0+)?$/'],
            'employee_id' => ['required_if:mode,individual', 'nullable', 'integer'],
            'recipients' => ['required_if:mode,bulk', 'nullable', Rule::in(['all', 'selected'])],
            'employee_ids' => ['required_if:recipients,selected', 'array'],
            'employee_ids.*' => ['integer'],
        ], $this->messages() + [
            'employee_id.required_if' => 'Choose an employee.',
            'employee_ids.required_if' => 'Tick at least one employee, or choose everyone enrolled.',
        ]);

        $employees = match (true) {
            $data['mode'] === 'individual' => $employer->employees()->whereKey($data['employee_id'])->get(),
            $data['recipients'] === 'all' => $employer->employees()->orderBy('id')->get(),
            default => $employer->employees()->whereKey($data['employee_ids'])->orderBy('id')->get(),
        };

        if ($employees->isEmpty()) {
            return back()->withInput()->withErrors(['employee_id' => 'Choose an employee from your team.']);
        }

        $cents = Money::toCents($data['amount']);
        $occasion = Occasion::from($data['occasion']);

        try {
            $ledger->allocateMany($employer, $employees, $cents, $occasion);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(route('employer.dashboard').'#allocate')->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        $who = $employees->count() === 1
            ? $employees->first()->publicLabel()
            : $employees->count().' employees';

        return redirect()->to(route('employer.dashboard').'#allocate')->with('status',
            Money::format($cents).' '.strtolower($occasion->label()).' given to '.$who
            .($employees->count() > 1 ? ' ('.Money::format($cents * $employees->count()).' in all)' : '').'.');
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'regex' => 'Enter an amount in dollars, like 150 or 12.50.',
            'not_regex' => 'Enter an amount greater than zero.',
            'required' => 'This is required.',
        ];
    }
}
