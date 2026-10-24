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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The employer/HR dashboard: program setup, buying credits in one step
 * (Stripe's embedded Checkout on this page in test mode, the credits locked
 * until Stripe's webhook confirms; or paid at once, simulated, when Stripe
 * keys are missing; ADR 0015), handing them to
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
            'buy' => $this->buyState($request, $employer),
            'metrics' => $metrics->for($employer),
            'balance' => $employer->balanceCents(),
            'pendingCents' => EmployerProgram::pendingCents($employer),
            'allocatedThisMonth' => EmployerProgram::allocatedInMonthCents($employer),
            'remainingBudget' => EmployerProgram::remainingBudgetCents($employer),
            'purchases' => $employer->creditPurchases()->latest('purchased_at')->latest('id')->take(8)->get(),
            'returned' => self::returnedCredit($employer),
            'allocationGroups' => self::recentAllocationGroups($employer),
            'employees' => $employees,
            'refundCorrection' => $employer->latestCorrection,
        ]);
    }

    /**
     * Back from Stripe's embedded Checkout (?checkout=returned&purchase=…):
     * once Stripe's signed webhook has marked it paid, the green toast says
     * so. Until then the Buy credits card shows "Confirming with Stripe…"
     * and checks by itself (buyState), so there is no toast yet.
     */
    private function checkoutMessage(Request $request, Employer $employer): void
    {
        $purchase = $request->query('checkout') === 'returned' ? $this->purchaseFromQuery($employer, 'purchase') : null;

        if ($purchase?->isStripe()) {
            $request->session()->now('status', Money::format($purchase->amount_cents)." in credits added (Stripe test mode, {$purchase->reference}".self::feeClause($purchase, 'charged').'). Ready to give.');
        }
    }

    /**
     * What the Buy credits card shows: "paying" (Stripe's card form for this
     * purchase), "confirming" (paid at Stripe, waiting for the webhook),
     * "cancelled" (the form again, filled in, with Try again) or the plain
     * form. Paying is only for someone who may buy; judges see the form.
     *
     * @return array{state: string, purchase: ?CreditPurchase}
     */
    private function buyState(Request $request, Employer $employer): array
    {
        $paying = StripeMode::active() && Gate::allows('manage-employer-program') ? $this->purchaseFromQuery($employer, 'pay') : null;
        $returned = $request->query('checkout') === 'returned' ? $this->purchaseFromQuery($employer, 'purchase') : null;
        $cancelled = $this->purchaseFromQuery($employer, 'cancelled');

        return match (true) {
            (bool) $paying?->isPending() => ['state' => 'paying', 'purchase' => $paying],
            (bool) $returned?->isPending() => ['state' => 'confirming', 'purchase' => $returned],
            (bool) $cancelled?->isCancelled() => ['state' => 'cancelled', 'purchase' => $cancelled],
            default => ['state' => 'form', 'purchase' => null],
        };
    }

    private function purchaseFromQuery(Employer $employer, string $key): ?CreditPurchase
    {
        $reference = request()->query($key);

        return is_string($reference) && $reference !== ''
            ? $employer->creditPurchases()->where('reference', $reference)->first()
            : null;
    }

    /** "; $102.50 charged, including a $2.50 platform fee", or nothing when the purchase has no fee (ADR 0021). */
    public static function feeClause(CreditPurchase $purchase, string $verb): string
    {
        return $purchase->platform_fee_cents > 0
            ? '; '.Money::format($purchase->totalCents()).' '.$verb.', including a '.Money::format($purchase->platform_fee_cents).' platform fee'
            : '';
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
            'entries' => $query()->with(['redemption.employee', 'redemption.decision', 'redemption.items'])->latest('created_at')->latest('id')->take($recent)->get(),
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

        return redirect()->to(route('employer.dashboard').'#program')->with('status',
            'Program saved. These amounts now fill in the Give credits form, and the monthly budget is checked on every gift from now on. Credits already given don\'t change.');
    }

    /**
     * "Buy credits", one step. Stripe test mode: the pending purchase is
     * written and the page comes back with Stripe's card form for it; the
     * credits unlock only when the webhook confirms. Simulated mode (no
     * Stripe keys): paid straight away with no real money.
     */
    public function storePurchase(Request $request, CreditLedger $ledger): RedirectResponse
    {
        Gate::authorize('manage-employer-program');
        $employer = $this->employer() ?? abort(404);

        $data = $request->validate([
            'purchase_amount' => ['required', self::DOLLARS, 'not_regex:/^0+(\.0+)?$/'],
        ], $this->messages());

        $purchase = $ledger->requestPurchase($employer, Money::toCents($data['purchase_amount']), EmployerProgram::nextReference($employer));

        if (StripeMode::active()) {
            return redirect()->to(route('employer.dashboard', ['pay' => $purchase->reference]).'#buy');
        }

        $ledger->payPurchase($purchase);

        return redirect()->to(route('employer.dashboard').'#credits')->with('status',
            Money::format($purchase->amount_cents)." in credits added (simulated, no real money, {$purchase->reference}".self::feeClause($purchase, 'paid').'). Ready to give.');
    }

    /**
     * Stripe's embedded Checkout asks for this (resources/js/checkout.js):
     * the client secret for the purchase's Checkout. Opening it writes
     * nothing to the ledger.
     */
    public function checkout(CreditPurchase $purchase, StripeCheckout $checkout): JsonResponse
    {
        $this->authorizeOwn($purchase);
        abort_unless(StripeMode::active(), 404);

        try {
            return response()->json(['client_secret' => $checkout->start($purchase)]);
        } catch (InvalidArgumentException|StripeGatewayException $e) {
            return response()->json(['message' => "Payment didn't start: {$e->getMessage()} Nothing was charged."], 422);
        }
    }

    /** "Confirming with Stripe…" asks this every few seconds until the webhook has decided. Reads only. */
    public function purchaseStatus(CreditPurchase $purchase): JsonResponse
    {
        abort_unless($purchase->employer_id === $this->employer()?->id, 404);

        return response()->json([
            'status' => in_array($purchase->status, CreditPurchase::PAID_STATUSES, true) ? 'paid' : $purchase->status,
            'error' => $purchase->payment_error,
        ]);
    }

    /**
     * "Continue paying" / "Try again" on a pending purchase. Stripe test
     * mode: the old Checkout is closed first, then a fresh one opens on this
     * page; if Stripe already has the payment, we wait for it instead.
     * Simulated mode: marks it paid with no real money, as before.
     */
    public function payPurchase(CreditPurchase $purchase, CreditLedger $ledger, StripeCheckout $checkout): RedirectResponse
    {
        $this->authorizeOwn($purchase);

        if (StripeMode::active()) {
            if (! $purchase->isPending()) {
                return redirect()->to(route('employer.dashboard').'#credits')->with('error', "Nothing changed: {$purchase->reference} is ".($purchase->isCancelled() ? 'cancelled' : 'already paid').'.');
            }

            try {
                $fresh = $checkout->restart($purchase);
            } catch (StripeGatewayException $e) {
                return redirect()->to(route('employer.dashboard').'#credits')->with('error', "Payment didn't start: {$e->getMessage()} Nothing was charged; {$purchase->reference} is still pending.");
            }

            return $fresh
                ? redirect()->to(route('employer.dashboard', ['pay' => $purchase->reference]).'#buy')
                : redirect()->to(route('employer.dashboard', ['checkout' => 'returned', 'purchase' => $purchase->reference]).'#buy');
        }

        try {
            $ledger->payPurchase($purchase);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(route('employer.dashboard').'#credits')->with('error', "Nothing changed: {$e->getMessage()}");
        }

        return redirect()->to(route('employer.dashboard').'#credits')->with('status',
            Money::format($purchase->amount_cents)." in credits added (simulated, no real money, {$purchase->reference}".self::feeClause($purchase, 'paid').'). Ready to give.');
    }

    /**
     * "Cancel payment". The purchase is cancelled only once Stripe confirms
     * its Checkout can't take a payment any more; if Stripe already has the
     * payment, it is confirmed instead. Never touches the ledger.
     */
    public function cancelPurchase(CreditPurchase $purchase, StripeCheckout $checkout): RedirectResponse
    {
        $this->authorizeOwn($purchase);

        if (! $purchase->isPending()) {
            return redirect()->to(route('employer.dashboard').'#credits')->with('error', "Nothing changed: {$purchase->reference} is ".($purchase->isCancelled() ? 'already cancelled' : 'already paid').'.');
        }

        if (StripeMode::active()) {
            try {
                $closed = $checkout->close($purchase);
            } catch (StripeGatewayException $e) {
                return redirect()->to(route('employer.dashboard', ['pay' => $purchase->reference]).'#buy')->with('error', "Couldn't cancel: {$e->getMessage()} Nothing was charged and nothing changed.");
            }

            if (! $closed) {
                return redirect()->to(route('employer.dashboard', ['checkout' => 'returned', 'purchase' => $purchase->reference]).'#buy')->with('notice',
                    "Stripe already has the payment for {$purchase->reference}, so it can't be cancelled. The credits arrive once Stripe confirms it.");
            }
        }

        CreditPurchase::whereKey($purchase->id)->where('status', CreditPurchase::STATUS_PENDING)->update(['status' => CreditPurchase::STATUS_CANCELLED]);

        return redirect()->to(route('employer.dashboard', ['cancelled' => $purchase->reference]).'#buy')->with('notice',
            'Payment cancelled. Nothing was charged for '.$purchase->reference.' ('.Money::format($purchase->amount_cents).' in credits).');
    }

    private function authorizeOwn(CreditPurchase $purchase): void
    {
        Gate::authorize('manage-employer-program');
        abort_unless($purchase->employer_id === $this->employer()?->id, 404);
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

        // "$50.00 sent to J.R." / "$50.00 birthday credit sent to each of 12 employees ($600.00 in all)". Initials only.
        $what = Money::format($cents).($occasion === Occasion::Monthly ? '' : ' '.strtolower($occasion->shortLabel()).' credit');
        $sent = $employees->count() === 1
            ? $what.' sent to '.$employees->first()->initials().' They can spend it now.'
            : $what.' sent to each of '.$employees->count().' employees ('.Money::format($cents * $employees->count()).' in all). They can spend it now.';

        return redirect()->to(route('employer.dashboard').'#allocate')->with('status', $sent);
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
