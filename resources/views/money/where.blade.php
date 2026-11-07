@php
    use App\Services\Solvency\SolvencyCheck;
    use App\Support\Money;
    use Illuminate\Support\Carbon;

    $dc = fn ($at) => $at === null ? '—' : (is_string($at) ? Carbon::parse($at, 'UTC') : $at)->setTimezone('America/New_York')->format('M j, Y, g:i A');
    $day = fn ($at) => $at === null ? '—' : $at->setTimezone('America/New_York')->format('M j, Y');
    $windows = $run?->failures['windows'] ?? [];
    $hasEarned = $earned['merchant_fee_sales'] > 0 || $earned['platform_fee_purchases'] > 0;
    $window = fn (array $w) => $dc($w['from']).' to '.($w['to'] === null ? 'now (still on)'
        : ($w['to']->copy()->setTimezone('America/New_York')->isSameDay($w['from']->copy()->setTimezone('America/New_York')) ? $w['to']->setTimezone('America/New_York')->format('g:i A') : $dc($w['to'])));
    $saleDays = $earned['first_sale_at'] === null ? null : ($day($earned['first_sale_at']) === $day($earned['last_sale_at'])
        ? $day($earned['first_sale_at']) : $day($earned['first_sale_at']).' to '.$day($earned['last_sale_at']));
@endphp

<x-app-shell title="Where the Money Is" active="money" context="Customer funds and the solvency rule">
<div class="mx-auto max-w-6xl space-y-10">
    <header class="mx-auto max-w-3xl">
        <x-eyebrow :center="true" class="text-accent-ink">Customer funds and the solvency rule</x-eyebrow>
        <h1 class="mt-1 text-center text-title font-semibold text-primary">Where the Money Is</h1>
        <p class="mt-2 text-center text-xl font-semibold text-ink">Where the money is</p>
        <p class="mt-3 text-lg text-muted">
            Employers pay for credits before anyone spends them. Until an employee spends them at a local merchant and the merchant is paid,
            that money is a promise Local Goods has made, and it has to be there. This page shows where every cent is,
            and the check that the money held has always covered every promise.
        </p>
        <p class="mt-3 flex flex-wrap items-center gap-2 text-sm text-muted">
            <x-money-mode />
            <span>Demo data: all people and transactions are fictional. Seeded history is simulated, never actual money.</span>
        </p>
    </header>

    <section aria-labelledby="tranches-heading" class="space-y-4">
        <h2 id="tranches-heading" class="text-2xl font-semibold text-primary">Two accounts, never mixed</h2>
        <p class="max-w-3xl text-muted">Employer money and Local Goods' own money are held in two separate Stripe test-mode accounts. Money moves from the first to the second only by a recorded sweep.</p>
        <div class="grid gap-4 lg:grid-cols-2">
            <x-card padding="p-5 sm:p-6" data-account="customer-funds">
                <h3 class="text-lg font-semibold">Customer funds account</h3>
                <p class="text-sm text-muted">Employer money only: a promise to pay. None of it is ours.</p>
                <p class="mt-3 text-sm"><span class="font-semibold">May be used for:</span> paying merchants for what staff spent, and refunding employers. Nothing else.</p>
                <p class="mt-3 text-sm font-semibold">Customer funds held</p>
                <p class="mt-3 font-display text-4xl font-semibold text-primary tabular" data-customer-funds>{{ Money::format($now->customerFundsCents()) }}</p>
                <x-rtable class="mt-4" label="Customer funds held, by where they are">
                    <table data-rtable="stack" class="w-full text-left text-sm">
                        <caption class="sr-only">Customer funds held, by where they are</caption>
                        <thead class="text-muted">
                            <tr><th scope="col" class="py-2 pr-4 font-semibold">Where</th><th scope="col" class="py-2 text-right font-semibold">Amount</th></tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr>
                                <th scope="row" data-primary class="py-2.5 pr-4 font-normal">Employer credits bought, not yet given out</th>
                                <td data-label="Amount" class="py-2.5 text-right tabular">{{ Money::format($now->employerCents) }}</td>
                            </tr>
                            <tr>
                                <th scope="row" data-primary class="py-2.5 pr-4 font-normal">Employee balances, not yet spent</th>
                                <td data-label="Amount" class="py-2.5 text-right tabular">{{ Money::format($now->employeeCents) }}</td>
                            </tr>
                            <tr>
                                <th scope="row" data-primary class="py-2.5 pr-4 font-normal">Owed to merchants, not yet transferred</th>
                                <td data-label="Amount" class="py-2.5 text-right tabular">{{ Money::format($now->merchantCents) }}</td>
                            </tr>
                            <tr>
                                <th scope="row" data-primary class="py-2.5 pr-4 font-normal">
                                    Held waiting for a person:
                                    {{ $now->heldRedemptions }} {{ Str::plural('redemption', $now->heldRedemptions) }} held by Redemption Fraud Detection,
                                    {{ $now->refundHolds }} employer {{ Str::plural('refund', $now->refundHolds) }} going through
                                </th>
                                <td data-label="Amount" class="py-2.5 text-right tabular">{{ Money::format($now->heldPendingCents()) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </x-rtable>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2" data-customer-funds-split>
                    <div>
                        <dt class="font-semibold">Paid through Stripe (test mode)</dt>
                        <dd class="tabular" data-stripe-backed>{{ Money::format($stripeBacked) }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold">Simulated, no money behind it</dt>
                        <dd class="tabular" data-simulated>{{ Money::format($simulated) }}</dd>
                    </div>
                </dl>
                @if ($simulated > 0)
                    <p class="mt-2 text-sm text-muted" data-simulated-note>
                        The simulated part comes from seeded demo purchases (and any bought while Stripe was off).
                        No money for it ever reached Stripe: it keeps the demo's books whole, but it is not money.
                    </p>
                @endif
                @if ($balances !== null && $balances['stripe'])
                    <p class="mt-4 text-sm" data-customer-stripe-balance>
                        <span class="font-semibold">In Stripe (the platform balance):</span>
                        @if ($balances['customer_cents'] !== null)
                            <span class="tabular">{{ Money::format($balances['customer_cents']) }}</span>.
                            Not the same as the amount held{{ $simulated > 0 ? ', mainly because '.Money::format($simulated).' of it is simulated and never reached Stripe. Also,' : ':' }}
                            in test mode Stripe takes its card fees from this balance, and it is shared with
                            other copies of Local Goods and test activity from before the last Reset demo. Reconciliation checks it.
                        @else
                            couldn't read it from Stripe just now.
                        @endif
                    </p>
                @endif
                <p class="mt-4 rounded-xl bg-band p-3 text-sm"><span class="font-semibold">Unspent credits always remain the employer's money, never ours.</span>
                    Local Goods never counts them as income, never spends or invests them, and an employer can ask for them back.</p>
            </x-card>

            <x-card padding="p-5 sm:p-6" data-account="operating">
                <h3 class="text-lg font-semibold">Local Goods Operating</h3>
                <p class="text-sm text-muted">Local Goods' own money: what it has earned and may spend.</p>
                <p class="mt-3 text-sm"><span class="font-semibold">May hold:</span> only money Local Goods has earned, moved here from the customer funds account by a recorded sweep. Never customer money.</p>
                @if ($showsFigures)
                    <dl class="mt-3 space-y-2 text-sm">
                        <div>
                            <dt class="font-semibold">In Stripe (Local Goods Operating's own balance)</dt>
                            <dd data-operating-stripe-balance>
                                @if ($balances === null || ! $balances['stripe'])
                                    Stripe test mode is off on this server, so there is no Stripe balance; sweeps are simulated.
                                @elseif (! $balances['operating_set_up'])
                                    Not set up in Stripe yet (platform admin: Reconciliation).
                                @elseif ($balances['operating_cents'] === null)
                                    Couldn't read it from Stripe just now.
                                @else
                                    <span class="font-display text-3xl font-semibold text-primary tabular">{{ Money::format($balances['operating_cents']) }}</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="font-semibold">Earned, not yet swept (still in the customer funds account)</dt>
                            <dd class="tabular" data-operating-funds>{{ Money::format($now->operatingCents) }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="mt-3 text-lg font-semibold" data-operating-funds="hidden">Shown to Local Goods staff only.</p>
                @endif
                <div class="mt-3 space-y-2 text-sm" data-earned-origin="{{ $hasEarned ? 'some' : 'none' }}">
                    @if ($hasEarned)
                        <p><span class="font-semibold">Where it came from:</span>
                            @if ($earned['merchant_fee_sales'] > 0)
                                {{ $earned['merchant_fee_sales'] }} {{ Str::plural('sale', $earned['merchant_fee_sales']) }} charged a merchant fee{{ $showsFigures ? ' ('.Money::format($earned['merchant_fee_cents']).' in all)' : '' }}, on {{ $saleDays }}@if ($earned['fee_windows'] !== []),
                                while the merchant fee was switched on {{ collect($earned['fee_windows'])->map(fn ($w) => 'from '.$window($w))->implode('; and ') }} (fee audit log)@endif.
                            @endif
                            @if ($earned['platform_fee_purchases'] > 0)
                                {{ $earned['platform_fee_purchases'] }} credit {{ Str::plural('purchase', $earned['platform_fee_purchases']) }} paid a platform fee{{ $showsFigures ? ' ('.Money::format($earned['platform_fee_cents']).' in all)' : '' }}.
                            @endif
                        </p>
                    @endif
                    <p data-charges-now="{{ $earned['charges_now'] ? 'yes' : 'no' }}">
                        @if ($earned['charges_now'])
                            A fee is switched on now, so new activity can earn Local Goods money.
                        @elseif ($hasEarned)
                            Otherwise Local Goods charges nothing: the platform fee and the merchant fee are both off now, so this is not ongoing income.
                        @else
                            Local Goods charges nothing: the platform fee and the merchant fee are both off, so it has earned nothing.
                        @endif
                    </p>
                </div>
            </x-card>
        </div>

        @if ($showsFigures)
            <x-card padding="p-5 sm:p-6">
                <h3 class="text-lg font-semibold">Money in hand</h3>
                <p class="text-sm text-muted">The money in the customer funds account, from the ledger: simulated and Stripe test-mode money together.</p>
                <x-rtable class="mt-3" label="Money in hand">
                    <table data-rtable="stack" class="w-full max-w-2xl text-left text-sm">
                        <caption class="sr-only">Money in hand: paid in, less paid out and refunded</caption>
                        <thead class="sr-only"><tr><th scope="col">Line</th><th scope="col">Amount</th></tr></thead>
                        <tbody class="divide-y divide-line">
                            <tr><th scope="row" data-primary class="py-2 pr-4 font-normal">Paid in by employers for credits</th><td data-label="Amount" class="py-2 text-right tabular">{{ Money::format($now->creditsPaidInCents) }}</td></tr>
                            <tr><th scope="row" data-primary class="py-2 pr-4 font-normal">Paid in as platform fees</th><td data-label="Amount" class="py-2 text-right tabular">{{ Money::format($now->feesPaidInCents) }}</td></tr>
                            <tr><th scope="row" data-primary class="py-2 pr-4 font-normal">Paid out to merchants</th><td data-label="Amount" class="py-2 text-right tabular">−{{ Money::format($now->paidOutCents) }}</td></tr>
                            <tr><th scope="row" data-primary class="py-2 pr-4 font-normal">Pulled back from merchants for refunds and reversals</th><td data-label="Amount" class="py-2 text-right tabular">{{ Money::format($now->pulledBackCents) }}</td></tr>
                            <tr><th scope="row" data-primary class="py-2 pr-4 font-normal">Refunded to employers</th><td data-label="Amount" class="py-2 text-right tabular">−{{ Money::format($now->refundedCents) }}</td></tr>
                            <tr><th scope="row" data-primary class="py-2 pr-4 font-normal">Swept to Local Goods Operating</th><td data-label="Amount" class="py-2 text-right tabular" data-swept>−{{ Money::format($now->sweptCents) }}</td></tr>
                            <tr class="font-semibold"><th scope="row" data-primary class="py-2 pr-4">Money in hand</th><td data-label="Amount" class="py-2 text-right tabular" data-in-hand>{{ Money::format($now->inHandCents()) }}</td></tr>
                            <tr><th scope="row" data-primary class="py-2 pr-4 font-normal">Promised: customer funds plus earned money not yet swept</th><td data-label="Amount" class="py-2 text-right tabular">{{ Money::format($now->promisedCents()) }}</td></tr>
                        </tbody>
                    </table>
                </x-rtable>
                <p class="mt-3 text-sm text-muted">Of that, Stripe test mode: {{ Money::format($now->stripeInCents) }} paid in, {{ Money::format($now->stripeOutCents) }} paid out.</p>
            </x-card>
        @endif
    </section>

    <section id="sweep" aria-labelledby="sweep-heading" class="scroll-mt-24 max-w-3xl space-y-3">
        <h2 id="sweep-heading" class="text-2xl font-semibold text-primary">Moving earned money to Local Goods Operating</h2>
        <p>
            Money Local Goods has earned leaves the customer funds account in only one way: a sweep, decided by the platform admin with a reason,
            recorded and never edited. The solvency check refuses a sweep that would leave customer funds short.
        </p>
        @if (! $showsFigures)
            <p class="text-sm text-muted" data-sweep="words">Amounts are shown to Local Goods staff only. Only the platform admin can sweep.</p>
        @elseif ($sweepable === 0)
            <p class="rounded-2xl bg-band p-4" data-sweep="nothing">
                <span class="font-semibold">Nothing to sweep.</span> Local Goods has no earned money left in the customer funds account,
                so every cent there belongs to employers, their staff or merchants.
            </p>
        @else
            <p data-sweep="available"><span class="font-semibold">Earned, not yet swept:</span> <span class="tabular" data-sweepable>{{ Money::format($sweepable) }}</span>
                ({{ $sweepChannel === 'stripe' ? 'swept by a Stripe test-mode transfer' : 'simulated, no real money' }}).</p>
            @if (! $maySweep)
                <p class="text-sm text-muted">Read-only: only the platform admin can sweep.</p>
            @elseif ($sweepReview === null)
                <form method="POST" action="{{ route('admin.operating.sweep.review') }}" data-busy="Reviewing…" class="space-y-3">
                    @csrf
                    <x-field name="amount" label="Amount in dollars" hint="At most the amount earned and not yet swept.">
                        <input id="amount" name="amount" type="text" inputmode="decimal" autocomplete="off" required value="{{ old('amount', Money::toDollars($sweepable)) }}"
                               class="w-40 rounded-lg border-2 border-muted bg-surface px-3 py-2 text-base tabular" aria-describedby="amount-hint @error('amount') amount-error @enderror" @error('amount') aria-invalid="true" @enderror>
                    </x-field>
                    <x-field name="reason" label="Reason" hint="Kept with the sweep for good. 5 to 500 characters.">
                        <textarea id="reason" name="reason" rows="2" required maxlength="500" class="w-full rounded-lg border-2 border-muted bg-surface px-3 py-2 text-base"
                                  aria-describedby="reason-hint @error('reason') reason-error @enderror" @error('reason') aria-invalid="true" @enderror>{{ old('reason') }}</textarea>
                    </x-field>
                    <x-button type="submit">Review the sweep</x-button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.operating.sweep') }}" data-busy="Sweeping…" class="space-y-3 rounded-2xl border-2 border-accent bg-band p-4" data-sweep-review>
                    @csrf
                    <p><span class="font-semibold">Review:</span> move <span class="tabular font-semibold">{{ Money::format($sweepReview['cents']) }}</span>
                        from the customer funds account to Local Goods Operating, {{ $sweepChannel === 'stripe' ? 'by a Stripe test-mode transfer' : 'simulated, no real money' }}.</p>
                    <p class="text-sm">Reason: {{ $sweepReview['reason'] }}</p>
                    <input type="hidden" name="amount_cents" value="{{ $sweepReview['cents'] }}">
                    <input type="hidden" name="reason" value="{{ $sweepReview['reason'] }}">
                    <input type="hidden" name="reviewed_against_cents" value="{{ $sweepReview['reviewed'] }}">
                    <label class="flex items-center gap-3"><input type="checkbox" name="confirm" value="1" required class="size-5"> I have checked the amount and the reason.</label>
                    <div class="flex flex-wrap gap-3">
                        <x-button type="submit" variant="accent">Confirm the sweep</x-button>
                        <a class="tap-target underline" href="{{ route('money') }}#sweep">Cancel</a>
                    </div>
                </form>
            @endif
        @endif

        <h3 class="pt-2 text-lg font-semibold">Sweep history</h3>
        @if ($sweeps->isEmpty())
            <p class="text-sm text-muted" data-sweep-history="empty">No sweeps yet. Every one will be listed here, with its date, amount, reason and who decided it.</p>
        @elseif (! $showsFigures)
            <p class="text-sm" data-sweep-history="words">{{ $sweeps->count() }} {{ Str::plural('sweep', $sweeps->count()) }} recorded. Amounts and reasons are shown to Local Goods staff only.</p>
        @else
            <x-rtable label="Sweeps to Local Goods Operating">
                <table data-rtable="stack" class="w-full text-left text-sm" data-sweep-history="table">
                    <caption class="sr-only">Every sweep from the customer funds account to Local Goods Operating, newest first</caption>
                    <thead class="border-b border-line text-muted">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-semibold">When</th>
                            <th scope="col" class="py-2 pr-4 text-right font-semibold">Amount</th>
                            <th scope="col" class="py-2 pr-4 font-semibold">How</th>
                            <th scope="col" class="py-2 pr-4 font-semibold">Reason</th>
                            <th scope="col" class="py-2 font-semibold">Decided by</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($sweeps as $sweep)
                            <tr data-sweep="{{ $sweep->status }}">
                                <td data-primary class="py-2.5 pr-4">{{ $dc($sweep->swept_at ?? $sweep->created_at) }}</td>
                                <td data-label="Amount" class="py-2.5 pr-4 text-right tabular">{{ Money::format($sweep->amount_cents) }}</td>
                                <td data-label="How" class="py-2.5 pr-4">
                                    @switch ($sweep->status)
                                        @case ('sent') <x-badge tone="ok">Stripe test-mode transfer</x-badge><code class="wrap-anywhere-safe block font-mono text-xs text-muted">{{ $sweep->stripe_transfer_id }}</code> @break
                                        @case ('simulated') <x-money-mode mode="simulated" /> @break
                                        @case ('failed') <x-badge tone="hold">Refused by Stripe, nothing moved</x-badge> @break
                                        @default <x-badge tone="neutral">Sending, not confirmed yet</x-badge>
                                    @endswitch
                                </td>
                                <td data-label="Reason" class="py-2.5 pr-4">{{ $sweep->reason }}</td>
                                <td data-label="Decided by" class="py-2.5">{{ $sweep->decidedBy ? \App\Models\User::initialsOf($sweep->decidedBy->name) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
        @endif
    </section>

    <section id="solvency-check" aria-labelledby="check-heading" class="space-y-4">
        <h2 id="check-heading" class="text-2xl font-semibold text-primary">The solvency check</h2>

        @if ($run === null)
            <p class="rounded-2xl bg-band p-4" data-solvency="not-run">
                <span class="font-semibold">Not run yet.</span> It runs after every deploy, after every tick of the demo activity generator,
                and when the platform admin presses Run the check now.
            </p>
        @elseif ($run->passed)
            <p class="rounded-2xl border-2 border-ok bg-ok-bg p-4 font-semibold text-ok" data-solvency="pass">
                <span aria-hidden="true">✓</span> Pass: at every moment in the ledger's history, the money held covered every promise.
            </p>
        @else
            <section role="alert" aria-labelledby="fail-heading" class="rounded-2xl border-2 border-danger bg-danger-bg p-5 text-danger" data-solvency="fail">
                <h3 id="fail-heading" class="text-xl font-semibold"><span aria-hidden="true">⚑</span> Fail: there were moments when the money held did not cover every promise</h3>
                <ul class="mt-3 list-disc space-y-2 pl-5">
                    @foreach ($windows as $window)
                        <li>
                            From {{ $dc($window['from']) }} to {{ $window['to'] === null ? 'now' : $dc($window['to']) }} (DC time): {{ $window['problem'] }}.
                            @if ($window['accounts'] !== [])
                                Below zero: {{ implode(', ', array_map(SolvencyCheck::accountLabel(...), $window['accounts'])) }}.
                            @endif
                        </li>
                    @endforeach
                    @foreach ($run->failures['mismatches'] ?? [] as $mismatch)
                        <li>{{ $mismatch }}</li>
                    @endforeach
                </ul>
                @if (($run->failures['window_count'] ?? 0) > count($windows))
                    <p class="mt-2 text-sm">And {{ $run->failures['window_count'] - count($windows) }} more.</p>
                @endif
            </section>
        @endif

        @if ($run !== null)
            <dl class="grid gap-3 sm:grid-cols-3">
                <x-card padding="p-4 sm:p-5">
                    <dt class="text-sm font-semibold text-muted">Last run</dt>
                    <dd class="mt-1 text-lg font-semibold" data-last-run>{{ $dc($run->created_at) }}</dd>
                    <dd class="mt-1 text-sm text-muted">By {{ $run->triggerLabel() }}, in {{ number_format($run->duration_ms) }} ms.</dd>
                </x-card>
                <x-card padding="p-4 sm:p-5">
                    <dt class="text-sm font-semibold text-muted">What it checked</dt>
                    <dd class="mt-1 text-lg font-semibold" data-entries-checked>{{ number_format($run->entries_checked) }} ledger {{ Str::plural('entry', $run->entries_checked) }}</dd>
                    <dd class="mt-1 text-sm text-muted">At {{ number_format($run->moments_checked) }} {{ Str::plural('moment', $run->moments_checked) }}, one after each change, never just at the end.</dd>
                </x-card>
                <x-card padding="p-4 sm:p-5">
                    <dt class="text-sm font-semibold text-muted">Over</dt>
                    <dd class="mt-1 text-lg font-semibold" data-date-range>{{ $day($run->first_entry_at) }} to {{ $day($run->last_entry_at) }}</dd>
                    <dd class="mt-1 text-sm text-muted">The whole history, from the first ledger entry.</dd>
                </x-card>
            </dl>
        @endif

        <p class="text-sm" data-solvency-now="{{ $now->passes() ? 'pass' : 'fail' }}">
            <span class="font-semibold">Right now:</span>
            @if ($now->passes())
                <x-badge tone="ok">Pass</x-badge> the money held equals everything promised, to the cent.
            @else
                <x-badge tone="danger">Fail</x-badge> {{ $now->problem() }}.
            @endif
        </p>

        @if ($mayRun)
            <form method="POST" action="{{ route('admin.solvency.run') }}" data-busy="Checking the whole ledger…">
                @csrf
                <x-button type="submit">Run the check now</x-button>
            </form>
        @else
            <p class="text-sm text-muted">Read-only: only the platform admin can run the check by hand.</p>
        @endif
    </section>

    <section aria-labelledby="meaning-heading" class="max-w-3xl space-y-3">
        <h2 id="meaning-heading" class="text-2xl font-semibold text-primary">What the rule means</h2>
        <p>
            <span class="font-semibold">The rule:</span> the money employers have paid in, less what has been paid out to merchants and refunded to employers,
            must always cover every promise still open: every employer's unspent credits, every employee's balance, everything owed to merchants,
            everything held waiting for a person, and what Local Goods has earned. Each promise counts on its own: one account below zero
            never makes up for another.
        </p>
        <p>
            <span class="font-semibold">Why it matters:</span> a prepaid program is only trustworthy if the money people were promised is always there.
            Local Goods never pays old promises with new money: if paying out, refunding or spending would leave less than has been promised,
            it is refused and nothing changes. The check replays the whole history in order, so a moment that went wrong in the past
            can't be hidden by a later correction.
        </p>
        <p data-separation-note>
            <span class="font-semibold">Two separate Stripe balances, still not a bank trust account.</span>
            Employer money is held in the customer funds account (Local Goods' Stripe test-mode platform balance). Local Goods' own money is held in
            a separate Stripe test-mode account, Local Goods Operating, and the only way money gets there is a recorded sweep of money Local Goods
            has earned, which the solvency check refuses if it would leave customer funds short. That is a real separation of balances.
            But the customer funds account is not a bank FBO ("for benefit of") or trust account, both accounts are controlled by Local Goods
            through Stripe, and in test mode Stripe takes its own card fees from the customer funds balance. The seeded history is simulated.
            A real deployment would hold customer funds at a bank or a regulated payments partner, and money-transmission rules may apply.
            What exactly is required needs legal advice, which we have not had.
        </p>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <h3 class="font-semibold">What it makes sure of</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    <li>At every recorded moment, every promise had money behind it, and no account was below zero.</li>
                    <li>Simulated money never paid for a Stripe test-mode transfer.</li>
                    <li>The ledger agrees with the purchase, payout and refund records it was written from.</li>
                </ul>
            </div>
            <div>
                <h3 class="font-semibold">What it doesn't cover</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    <li>That customer money is held the way a real deployment would need: it is in a Stripe test-mode balance Local Goods controls, not a bank FBO or trust account.</li>
                    <li>That Stripe's own balance matches: that is the reconciliation check, in Stripe test mode only.</li>
                    <li>Stripe's card fees: in test mode Stripe takes them from the customer funds balance, outside this ledger. A real deployment would pay them from Local Goods' own money.</li>
                    <li>Money in flight between Stripe acting and telling us.</li>
                    <li>Simulated money is not real money.</li>
                </ul>
            </div>
        </div>
        <p class="text-sm text-muted">How it works: <a class="tap-target underline" rel="noopener" href="https://github.com/finnbutler1/local-goods/blob/main/docs/adr/0033-customer-funds-and-the-solvency-rule.md">decision record 0033</a>, and the two accounts:
            <a class="tap-target underline" rel="noopener" href="https://github.com/finnbutler1/local-goods/blob/main/docs/adr/0034-two-separate-stripe-balances.md">decision record 0034</a>.</p>
    </section>
</div>
</x-app-shell>
