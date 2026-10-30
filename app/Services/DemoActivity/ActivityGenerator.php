<?php

namespace App\Services\DemoActivity;

use App\Enums\Occasion;
use App\Models\Correction;
use App\Models\CreditAllocation;
use App\Models\CreditPurchase;
use App\Models\DemoActivityAction;
use App\Models\DemoActivityDay;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Corrections\CorrectionRequests;
use App\Services\CreditLedger;
use App\Services\EmployerProgram;
use App\Services\Payments\MerchantPayouts;
use App\Services\Payments\StripeGatewayException;
use App\Services\Payments\StripeReconciliation;
use App\Services\PayCodes;
use App\Services\RedemptionBasket;
use App\Services\RedemptionService;
use App\Support\MerchantFee;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;

/**
 * The demo activity generator (ADR 0032). One tick carries out the slots of
 * today's plan that are due, through the normal services only: buying
 * credits (Stripe test card, paid by the signed webhook), allowances,
 * redemptions to go and in person (the real fraud check, ledger and Stripe
 * transfer), and the occasional refund request from the demo merchant's
 * login. It never writes a money row itself, never approves, denies or
 * decides anything (a person does that), and never throws: a failure is
 * logged on its action row and three in a day stop that day.
 */
class ActivityGenerator
{
    /** @var list<string> */
    private array $log = [];

    public function __construct(
        private DayPlanner $planner,
        private CreditLedger $ledger,
        private RedemptionService $redemptions,
        private PayCodes $payCodes,
        private CorrectionRequests $corrections,
        private MerchantPayouts $payouts,
        private StripeTestPayments $testPayments,
        private StripeReconciliation $reconciliation,
    ) {}

    /** @return list<string> what happened, one line each */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(ActivityGuard::TIMEZONE);
        $this->log = [];

        if (($refusal = ActivityGuard::refusal($now)) !== null) {
            return [$refusal];
        }

        try {
            $day = $this->day($now);
            if ($day->isHalted()) {
                return ['Stopped for today: '.$day->halted_reason];
            }

            $this->reconcile($day);
            $employer = Employer::orderBy('id')->first();

            if ($employer !== null && ! $day->isHalted()) {
                $this->topUp($day, $employer, $now);
                $this->allowances($day, $employer, $now);
                $this->dueSlots($day, $employer, $now);
                $this->sendOwedPayouts();
            }
        } catch (Throwable $e) {
            Log::warning('Demo activity tick failed', ['error' => $e->getMessage()]);
            $this->log[] = 'Tick failed: '.$e->getMessage();
        }

        return $this->log === [] ? ['Nothing due.'] : $this->log;
    }

    /** Today's row, with the plan drawn for this date the first time. */
    public function day(CarbonImmutable $now): DemoActivityDay
    {
        $date = $now->setTimezone(ActivityGuard::TIMEZONE)->startOfDay();

        $existing = DemoActivityDay::whereDate('date', $date->toDateString())->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return DemoActivityDay::create(['date' => $date->toDateString(), 'seed' => DayPlanner::seedFor($date), 'plan' => $this->planner->plan($date)]);
        } catch (UniqueConstraintViolationException) {
            return DemoActivityDay::whereDate('date', $date->toDateString())->firstOrFail();
        }
    }

    /**
     * Reconciliation after the last tick's webhooks have had time to arrive.
     * Only when something was done since the last check. Two mismatches in a
     * row stop the day (one can be a webhook still on its way).
     */
    private function reconcile(DemoActivityDay $day): void
    {
        $done = $day->actions()->where('status', DemoActivityAction::STATUS_DONE)->count();
        if ($done === 0 || ($day->last_reconciliation['actions'] ?? null) === $done) {
            return;
        }

        $report = $this->reconciliation->report();
        $mismatches = $report['error'] !== null ? [$report['error']] : $report['mismatches'];
        $misses = $mismatches === [] ? 0 : $day->reconciliation_misses + 1;

        $day->forceFill([
            'reconciliation_misses' => $misses,
            'last_reconciliation' => ['at' => now()->toIso8601String(), 'actions' => $mismatches === [] ? $done : null, 'matches' => $mismatches === [], 'mismatches' => array_slice($mismatches, 0, 5)],
        ])->save();

        if ($misses >= (int) config('demo_activity.caps.reconciliation_misses')) {
            $this->halt($day, 'reconciliation did not match twice in a row. Check /admin/reconciliation.');
        } elseif ($mismatches !== []) {
            $this->log[] = 'Reconciliation did not match (once; checking again next tick).';
        } else {
            $this->log[] = 'Reconciliation matches.';
        }
    }

    /**
     * Buys credits when the employer is running low: enough for this month's
     * allowances still to give, or a top-up when the Stripe-paid money not
     * yet used for payouts runs low. One pending generated purchase at a time.
     */
    private function topUp(DemoActivityDay $day, Employer $employer, CarbonImmutable $now): void
    {
        $pending = DemoActivityAction::where('kind', DemoActivityAction::KIND_PURCHASE)
            ->whereHas('purchase', fn ($q) => $q->where('status', CreditPurchase::STATUS_PENDING))->exists();
        if ($pending) {
            return;
        }

        $owed = $this->employeesWithoutAllowance($employer, $now)->count() * (int) $employer->monthly_allowance_cents;
        $unclaimed = CreditPurchase::where('employer_id', $employer->id)->where('status', CreditPurchase::STATUS_PAID)
            ->whereNotNull('stripe_charge_id')->get()->sum(fn (CreditPurchase $p) => $p->unclaimedCents());
        $balance = $employer->balanceCents();

        $needed = max(0, $owed - $balance);
        if ($needed === 0 && $unclaimed >= (int) config('demo_activity.top_up.below_cents')) {
            return;
        }

        $amount = max($needed, (int) config('demo_activity.top_up.amount_cents'));
        $amount = (int) ceil($amount / 50000) * 50000;
        $left = (int) config('demo_activity.caps.purchase_cents_per_day') - $this->doneCents($day, DemoActivityAction::KIND_PURCHASE);
        $amount = min($amount, intdiv(max(0, $left), 50000) * 50000);

        if ($amount <= 0) {
            return;
        }

        $slot = 'purchase-'.($day->actions()->where('kind', DemoActivityAction::KIND_PURCHASE)->count() + 1);
        $this->attempt($day, $slot, DemoActivityAction::KIND_PURCHASE, $now, [], function (DemoActivityAction $action) use ($employer, $amount) {
            $purchase = $this->ledger->requestPurchase($employer, $amount, EmployerProgram::nextReference($employer));
            $action->forceFill(['credit_purchase_id' => $purchase->id, 'amount_cents' => $amount])->save();

            try {
                $this->testPayments->pay($purchase);
            } catch (StripeGatewayException $e) {
                // Stripe definitely took no money: cancel, as "Cancel payment" would. If
                // we can't tell (a timeout), it stays pending and the webhook decides.
                if ($e->definite) {
                    CreditPurchase::whereKey($purchase->id)->where('status', CreditPurchase::STATUS_PENDING)->update(['status' => CreditPurchase::STATUS_CANCELLED]);
                }

                throw $e;
            }

            return 'Bought '.Money::format($amount).' of credits ('.$purchase->reference.') with Stripe\'s test card; the webhook marks it paid.';
        });
    }

    /**
     * This month's allowance for each employee who hasn't had one, as many as
     * the paid balance and the month's budget cover. The rest wait for the
     * next tick (after a purchase is paid).
     */
    private function allowances(DemoActivityDay $day, Employer $employer, CarbonImmutable $now): void
    {
        $amount = (int) $employer->monthly_allowance_cents;
        $missing = $this->employeesWithoutAllowance($employer, $now);

        if ($amount <= 0 || $missing->isEmpty()) {
            return;
        }

        $room = min($employer->balanceCents(), EmployerProgram::remainingBudgetCents($employer) ?? PHP_INT_MAX);
        $batch = $missing->take(intdiv(max(0, $room), $amount));
        if ($batch->isEmpty()) {
            return;
        }

        $slot = 'allowance-'.($day->actions()->where('kind', DemoActivityAction::KIND_ALLOWANCE)->count() + 1);
        $this->attempt($day, $slot, DemoActivityAction::KIND_ALLOWANCE, $now, [], function (DemoActivityAction $action) use ($employer, $batch, $amount) {
            $this->ledger->allocateMany($employer, $batch, $amount, Occasion::Monthly);
            $action->forceFill(['amount_cents' => $amount * $batch->count()])->save();

            return 'Gave '.$batch->count().' employees this month\'s '.Money::format($amount).' allowance.';
        });
    }

    /** @return Collection<int, Employee> employees with no monthly allowance yet this month (DC time) */
    private function employeesWithoutAllowance(Employer $employer, CarbonImmutable $now): Collection
    {
        [$start, $end] = [$now->startOfMonth()->utc(), $now->endOfMonth()->utc()];
        $had = CreditAllocation::where('employer_id', $employer->id)->whereBetween('allocated_at', [$start, $end])
            ->where(fn ($q) => $q->whereNull('occasion')->orWhere('occasion', Occasion::Monthly->value))
            ->pluck('employee_id');

        return Employee::where('employer_id', $employer->id)->whereNotIn('id', $had)->orderBy('id')->get();
    }

    private function dueSlots(DemoActivityDay $day, Employer $employer, CarbonImmutable $now): void
    {
        $claimed = $day->actions()->pluck('slot')->all();
        $due = collect($day->plan)
            ->filter(fn ($slot) => $slot['at'] <= $now->format('H:i') && ! in_array($slot['slot'], $claimed, true))
            ->take((int) config('demo_activity.max_slots_per_tick'));

        foreach ($due as $slot) {
            $day->refresh();
            if ($day->isHalted()) {
                return;
            }

            $planned = $now->setTimeFromTimeString($slot['at']);
            match ($slot['kind']) {
                DemoActivityAction::KIND_REDEMPTION => $this->redemption($day, $employer, $slot, $planned),
                DemoActivityAction::KIND_RECOGNITION => $this->recognition($day, $employer, $slot, $planned),
                DemoActivityAction::KIND_REFUND_REQUEST => $this->refundRequest($day, $slot, $planned),
                default => null,
            };
        }
    }

    private function redemption(DemoActivityDay $day, Employer $employer, array $slot, CarbonImmutable $planned): void
    {
        $held = Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->count();
        if ($held > (int) config('demo_activity.queue.held_waiting')) {
            $this->halt($day, $held.' held payments are waiting for a person, so no new redemptions today.');

            return;
        }

        $merchant = Merchant::where('name', DatabaseSeeder::DEMO_MERCHANTS[$slot['merchant']][0] ?? null)->first();
        $context = ['merchant_id' => $merchant?->id, 'channel' => $slot['channel'], 'unusual' => (bool) $slot['unusual']];

        $this->attempt($day, $slot['slot'], DemoActivityAction::KIND_REDEMPTION, $planned, $context, function (DemoActivityAction $action) use ($day, $employer, $slot, $merchant) {
            if ($merchant === null) {
                return $this->skip($action, 'That demo merchant is not in the database.');
            }

            $basket = $this->basket($merchant, $slot);
            if ($basket === null) {
                return $this->skip($action, 'None of the planned items are on the menu now.');
            }

            $total = $basket['total_cents'];
            if ($this->doneCents($day, DemoActivityAction::KIND_REDEMPTION) + $total > (int) config('demo_activity.caps.spend_cents_per_day')) {
                return $this->skip($action, 'The daily spending cap is reached.');
            }

            $employee = $this->pickEmployee($day, $employer, $total, $slot);
            if ($employee === null) {
                return $this->skip($action, 'Nobody has '.Money::format($total).' of credits left.');
            }
            $action->forceFill(['employee_id' => $employee->id, 'amount_cents' => $total])->save();

            if ($slot['channel'] === 'in_person') {
                $issued = $this->payCodes->issue($employee, $merchant, $total, $basket['items'], $basket['tax']);
                // The merchant types the code it was shown; it is never logged or stored by the generator.
                $redemption = $this->payCodes->confirm($this->payCodes->lookup($merchant, $issued->code), $merchant, MerchantFee::current());
            } else {
                $redemption = $this->redemptions->redeem($employee, $merchant, $total, $basket['items'], tax: $basket['tax']);
            }

            $action->forceFill(['redemption_id' => $redemption->id])->save();

            return $employee->initials().' spent '.Money::format($total).' at '.$merchant->name
                .($slot['channel'] === 'in_person' ? ' in person' : ' to go')
                .($redemption->isUnderReview() ? '; Redemption Fraud Detection held it for a person.' : '; cleared.');
        });
    }

    /** @return array{total_cents: int, items: array, tax: mixed}|null */
    private function basket(Merchant $merchant, array $slot): ?array
    {
        if ($slot['items'] === null) {
            return RedemptionBasket::build($merchant, [], (int) $slot['amount_cents']);
        }

        $products = $merchant->products()->available()->whereIn('name', array_keys($slot['items']))->get();
        if ($products->isEmpty()) {
            return null;
        }

        return RedemptionBasket::build($merchant, $products->mapWithKeys(fn ($p) => [$p->id => $slot['items'][$p->name]])->all(), 0);
    }

    /**
     * Someone who can afford it, hasn't been picked today and isn't Jordan
     * or a planted collusion pattern. An unusual slot prefers someone for
     * whom it is most of their balance. Drawn from the date and slot, so the
     * same state picks the same person.
     */
    private function pickEmployee(DemoActivityDay $day, Employer $employer, int $total, array $slot): ?Employee
    {
        $usedToday = $day->actions()->where('kind', DemoActivityAction::KIND_REDEMPTION)->whereNotNull('employee_id')->pluck('employee_id');
        $candidates = Employee::where('employer_id', $employer->id)
            ->whereNotIn('display_name', config('demo_activity.excluded_employees'))
            ->whereNotIn('id', $usedToday)->orderBy('id')->get()
            ->filter(fn (Employee $e) => $e->balanceCents() >= $total)->values();

        if ($slot['unusual']) {
            $most = $candidates->filter(fn (Employee $e) => $total >= 0.8 * $e->balanceCents())->values();
            $candidates = $most->isNotEmpty() ? $most : $candidates;
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        $random = new Randomizer(new Mt19937(crc32($day->date->toDateString().':'.$slot['slot']) & 0x7fffffff));

        return $candidates[$random->getInt(0, $candidates->count() - 1)];
    }

    private function recognition(DemoActivityDay $day, Employer $employer, array $slot, CarbonImmutable $planned): void
    {
        $this->attempt($day, $slot['slot'], DemoActivityAction::KIND_RECOGNITION, $planned, [], function (DemoActivityAction $action) use ($day, $employer) {
            $amount = (int) $employer->recognition_cents;
            if ($amount <= 0) {
                return $this->skip($action, 'Recognition is off for this employer.');
            }

            $people = Employee::where('employer_id', $employer->id)->whereNotIn('display_name', config('demo_activity.excluded_employees'))->orderBy('id')->get();
            $random = new Randomizer(new Mt19937(crc32($day->date->toDateString().':recognition') & 0x7fffffff));
            $employee = $people[$random->getInt(0, $people->count() - 1)];

            $this->ledger->allocateMany($employer, [$employee], $amount, Occasion::Recognition);
            $action->forceFill(['employee_id' => $employee->id, 'amount_cents' => $amount])->save();

            return 'Gave '.$employee->initials().' a '.Money::format($amount).' recognition award.';
        });
    }

    /**
     * Files one refund request as the demo merchant's login, on one of its own
     * generated orders whose payout went through Stripe. Asking moves no
     * money; a platform admin decides it, with a reason.
     */
    private function refundRequest(DemoActivityDay $day, array $slot, CarbonImmutable $planned): void
    {
        $merchant = Merchant::where('name', config('demo.accounts.merchant.business'))->first();

        $this->attempt($day, $slot['slot'], DemoActivityAction::KIND_REFUND_REQUEST, $planned, ['merchant_id' => $merchant?->id], function (DemoActivityAction $action) use ($merchant, $day) {
            $open = Correction::whereIn('status', Correction::OPEN_STATUSES)->count();
            if ($open > (int) config('demo_activity.queue.refunds_waiting')) {
                return $this->skip($action, $open.' refund requests are waiting for a person, so none new today.');
            }

            $login = $merchant?->user_id ? User::find($merchant->user_id) : null;
            if ($login === null) {
                return $this->skip($action, 'The demo merchant has no login to ask from.');
            }

            $cap = (int) config('demo_activity.caps.refund_cents_per_day');
            $order = Redemption::where('merchant_id', $merchant->id)->where('status', Redemption::STATUS_APPROVED)
                ->whereHas('demoActivity')->whereHas('items')->whereDoesntHave('corrections')
                ->whereHas('stripePayout', fn ($q) => $q->where('status', MerchantPayout::STATUS_SENT))
                ->where('created_at', '>=', now()->subDays(7))->with('items')->latest('id')->get()
                ->first(fn (Redemption $r) => $r->items->min('unit_price_cents') <= $cap);

            if ($order === null) {
                return $this->skip($action, 'No recent order of theirs was paid out through Stripe yet.');
            }

            $item = $order->items->sortBy('unit_price_cents')->first();
            $reasons = config('demo_activity.refund_reasons');
            $random = new Randomizer(new Mt19937(crc32($day->date->toDateString().':refund') & 0x7fffffff));
            [$reason, $note] = $reasons[$random->getInt(0, count($reasons) - 1)];

            $correction = $this->corrections->forRedemption($login, $order, $reason, $note.' ('.$item->name.')');
            $action->forceFill(['correction_id' => $correction->id, 'redemption_id' => null, 'employee_id' => $order->employee_id, 'amount_cents' => $item->unit_price_cents])->save();

            return $merchant->name.' asked for a refund on '.$order->reference().' ('.$item->name.'); waiting for a person to decide.';
        });
    }

    /** Payouts left owed (say, a merchant who finished onboarding since). Never the failed ones: those are for a person. */
    private function sendOwedPayouts(): void
    {
        $merchants = MerchantPayout::where('status', MerchantPayout::STATUS_OWED)->distinct()->pluck('merchant_id');

        foreach (Merchant::whereIn('id', $merchants)->get() as $merchant) {
            $this->payouts->sendSafely($merchant);
        }
    }

    /**
     * Claims the slot (unique per day, so a second run never does it twice),
     * checks the daily action cap, then runs it. Never throws.
     *
     * @param  callable(DemoActivityAction): string  $work
     */
    private function attempt(DemoActivityDay $day, string $slot, string $kind, CarbonImmutable $planned, array $context, callable $work): void
    {
        if ($day->actions()->whereIn('status', [DemoActivityAction::STATUS_DONE, DemoActivityAction::STATUS_FAILED])->count() >= (int) config('demo_activity.caps.actions_per_day')) {
            $this->halt($day, 'the daily cap of '.config('demo_activity.caps.actions_per_day').' actions is reached.');

            return;
        }

        try {
            $action = DemoActivityAction::create([
                'day_id' => $day->id, 'slot' => $slot, 'kind' => $kind, 'status' => DemoActivityAction::STATUS_CLAIMED,
                'planned_for' => $planned->utc(), 'ran_at' => now(), 'unusual' => (bool) ($context['unusual'] ?? false),
                'merchant_id' => $context['merchant_id'] ?? null, 'channel' => $context['channel'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        try {
            $line = $work($action);
            if ($action->status === DemoActivityAction::STATUS_CLAIMED) {
                $action->forceFill(['status' => DemoActivityAction::STATUS_DONE, 'note' => mb_substr($line, 0, 250)])->save();
            }
            $this->log[] = $line;
        } catch (Throwable $e) {
            $action->forceFill(['status' => DemoActivityAction::STATUS_FAILED, 'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000)])->save();
            Log::warning('Demo activity action failed', ['slot' => $slot, 'kind' => $kind, 'error' => $e->getMessage()]);
            $this->log[] = 'Failed '.$slot.': '.$e->getMessage();

            $failures = $day->actions()->where('status', DemoActivityAction::STATUS_FAILED)->count();
            if ($failures >= (int) config('demo_activity.caps.failures_per_day')) {
                $this->halt($day, $failures.' actions failed today.');
            }
        }
    }

    private function skip(DemoActivityAction $action, string $why): string
    {
        $action->forceFill(['status' => DemoActivityAction::STATUS_SKIPPED, 'note' => $why])->save();

        return 'Skipped '.$action->slot.': '.$why;
    }

    private function halt(DemoActivityDay $day, string $why): void
    {
        $day->forceFill(['halted_reason' => mb_substr($why, 0, 250)])->save();
        Log::warning('Demo activity stopped for today', ['reason' => $why]);
        $this->log[] = 'Stopped for today: '.$why;
    }

    private function doneCents(DemoActivityDay $day, string $kind): int
    {
        return (int) $day->actions()->where('kind', $kind)->whereIn('status', [DemoActivityAction::STATUS_DONE, DemoActivityAction::STATUS_CLAIMED])->sum('amount_cents');
    }
}
