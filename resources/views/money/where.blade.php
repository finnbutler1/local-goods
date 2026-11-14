@php
    use App\Services\Solvency\SolvencyCheck;
    use App\Support\Money;
    use Illuminate\Support\Carbon;

    $dc = fn ($at) => $at === null ? '—' : (is_string($at) ? Carbon::parse($at, 'UTC') : $at)->setTimezone('America/New_York')->format('M j, Y, g:i A');
    $day = fn ($at) => $at === null ? '—' : $at->setTimezone('America/New_York')->format('M j, Y');
    $windows = $run?->failures['windows'] ?? [];
    $hasEarned = $earned['merchant_fee_sales'] > 0 || $earned['platform_fee_purchases'] > 0 || $earned['employer_fee_purchases'] > 0 || $earned['bid_fee_years'] > 0;
    $window = fn (array $w) => $dc($w['from']).' to '.($w['to'] === null ? 'now (still on)'
        : ($w['to']->copy()->setTimezone('America/New_York')->isSameDay($w['from']->copy()->setTimezone('America/New_York')) ? $w['to']->setTimezone('America/New_York')->format('g:i A') : $dc($w['to'])));
    $saleDays = $earned['first_sale_at'] === null ? null : ($day($earned['first_sale_at']) === $day($earned['last_sale_at'])
        ? $day($earned['first_sale_at']) : $day($earned['first_sale_at']).' to '.$day($earned['last_sale_at']));
@endphp

<x-app-shell title="Where the Money Is" active="money" context="Customer funds and the solvency rule">
<div class="admin-desk space-y-14">
    <header>
        <x-station-sign as="p">Customer funds and the solvency rule</x-station-sign>
        <h1 class="page-title">Where the Money Is</h1>
        <p class="mt-3 text-2xl font-bold tracking-tight text-ink">Where the money is</p>
        <p class="page-lede">
            Employers pay for credits before anyone spends them. Until an employee spends them at a local merchant and the merchant is paid,
            that money is a promise Local Goods has made. The money has to be there. This page shows where every cent is,
            and a check of whether the money held has always covered every promise.
        </p>
        <p class="mt-4 flex max-w-[52rem] flex-wrap items-center gap-2 text-sm text-muted">
            <x-money-mode />
            <span>Demo data: all people and transactions are fictional. Seeded history is simulated, never actual money.</span>
        </p>
    </header>

    <section aria-labelledby="tranches-heading" class="rule-heavy">
        <h2 id="tranches-heading" class="stop-subtitle text-2xl">Two accounts, never mixed</h2>
        <p class="mt-2 max-w-[52rem] text-muted">Employer money and Local Goods' own money are held in two separate Stripe test-mode accounts. Money moves from the first to the second only by a recorded sweep.</p>
        <div class="mt-10 grid gap-y-14 lg:grid-cols-2 lg:gap-x-12">
            <section aria-labelledby="customer-funds-heading" data-account="customer-funds">
                <h3 id="customer-funds-heading" class="statement-head text-xl font-bold tracking-tight">Customer funds account</h3>
                <p class="mt-3 text-sm text-muted">Employer money only: a promise to pay. None of it is ours.</p>
                <p class="mt-2 text-sm"><span class="font-semibold">May be used for:</span> paying merchants for what staff spent, and refunding employers. Nothing else.</p>
                <div class="figure-cell">
                    <p class="figure-label mt-6">Customer funds held</p>
                    <p class="money mt-1 text-5xl text-primary" data-customer-funds>{{ Money::format($now->customerFundsCents()) }}</p>
                </div>
                <x-rtable class="mt-6" label="Customer funds held, by where they are">
                    <table data-rtable="fit" class="statement text-left text-sm">
                        <caption class="sr-only">Customer funds held, by where they are</caption>
                        <thead>
                            <tr><th scope="col" class="text-left">Where</th><th scope="col" class="text-right">Amount</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th scope="row" data-primary class="font-normal">Employer credits bought, not yet given out</th>
                                <td data-label="Amount" class="tabular">{{ Money::format($now->employerCents) }}</td>
                            </tr>
                            <tr>
                                <th scope="row" data-primary class="font-normal">Employee balances, not yet spent</th>
                                <td data-label="Amount" class="tabular">{{ Money::format($now->employeeCents) }}</td>
                            </tr>
                            <tr>
                                <th scope="row" data-primary class="font-normal">Owed to merchants, not yet transferred</th>
                                <td data-label="Amount" class="tabular">{{ Money::format($now->merchantCents) }}</td>
                            </tr>
                            <tr>
                                <th scope="row" data-primary class="font-normal">
                                    Held waiting for a person:
                                    {{ $now->heldRedemptions }} {{ Str::plural('redemption', $now->heldRedemptions) }} held by Redemption Fraud Detection,
                                    {{ $now->refundHolds }} employer {{ Str::plural('refund', $now->refundHolds) }} going through
                                </th>
                                <td data-label="Amount" class="tabular">{{ Money::format($now->heldPendingCents()) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </x-rtable>
                <dl class="mt-6 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2" data-customer-funds-split>
                    <div class="border-t border-ink pt-2">
                        <dt class="font-semibold">Paid through Stripe (test mode)</dt>
                        <dd class="ledger-num mt-1 text-lg" data-stripe-backed>{{ Money::format($stripeBacked) }}</dd>
                    </div>
                    <div class="border-t border-ink pt-2">
                        <dt class="font-semibold">Simulated, no money behind it</dt>
                        <dd class="ledger-num mt-1 text-lg" data-simulated>{{ Money::format($simulated) }}</dd>
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
                            That is not the same as the amount held{{ $simulated > 0 ? ', mainly because '.Money::format($simulated).' of it is simulated and never reached Stripe. Also,' : ':' }}
                            in test mode Stripe takes its card fees from this balance. It is also shared with
                            other copies of Local Goods and with test activity from before the last Reset demo. Reconciliation checks it.
                        @else
                            couldn't read it from Stripe just now.
                        @endif
                    </p>
                @endif
                <p class="mt-6 border-t-[3px] border-ink bg-band px-4 py-3 text-sm"><span class="font-semibold">Unspent credits are customer funds held for the program, never Local Goods' money.</span>
                    Credits an employer hasn't given out yet stay on its balance and can be refunded on request; credits given to an employee
                    stay in their wallet, with no expiry. Local Goods never counts them as income and never spends or invests them.</p>
            </section>

            <section aria-labelledby="operating-heading" class="lg:border-l lg:border-ink lg:pl-12" data-account="operating">
                <h3 id="operating-heading" class="statement-head text-xl font-bold tracking-tight">Local Goods Operating</h3>
                <p class="mt-3 text-sm text-muted">Local Goods' own money: what it has earned and may spend.</p>
                <p class="mt-2 text-sm"><span class="font-semibold">May hold:</span> only money Local Goods has earned, moved here from the customer funds account by a recorded sweep. Never customer money.</p>
                @if ($showsFigures)
                    <dl class="mt-6 space-y-5 text-sm">
                        <div class="border-t border-ink pt-2">
                            <dt class="font-semibold">In Stripe (Local Goods Operating's own balance)</dt>
                            <dd class="mt-1" data-operating-stripe-balance>
                                @if ($balances === null || ! $balances['stripe'])
                                    Stripe test mode is off on this server, so there is no Stripe balance; sweeps are simulated.
                                @elseif (! $balances['operating_set_up'])
                                    Not set up in Stripe yet (platform admin: Reconciliation).
                                @elseif ($balances['operating_cents'] === null)
                                    Couldn't read it from Stripe just now.
                                @else
                                    <span class="money text-4xl text-primary">{{ Money::format($balances['operating_cents']) }}</span>
                                @endif
                            </dd>
                        </div>
                        <div class="border-t border-ink pt-2">
                            <dt class="font-semibold">Earned, not yet swept (still in the customer funds account)</dt>
                            <dd class="ledger-num mt-1 text-lg" data-operating-funds>{{ Money::format($now->operatingCents) }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="mt-6 border-t border-ink pt-2 text-lg font-semibold" data-operating-funds="hidden">Shown to Local Goods staff only.</p>
                @endif
                <div class="mt-5 space-y-2 text-sm" data-earned-origin="{{ $hasEarned ? 'some' : 'none' }}">
                    @if ($hasEarned)
                        <p><span class="font-semibold">Where it came from:</span>
                            @if ($earned['merchant_fee_sales'] > 0)
                                {{ $earned['merchant_fee_sales'] }} {{ Str::plural('sale', $earned['merchant_fee_sales']) }} charged a merchant fee{{ $showsFigures ? ' ('.Money::format($earned['merchant_fee_cents']).' in all)' : '' }}, on {{ $saleDays }}@if ($earned['fee_windows'] !== []),
                                while the merchant fee was switched on {{ collect($earned['fee_windows'])->map(fn ($w) => 'from '.$window($w))->implode('; and ') }} (fee audit log)@endif.
                            @endif
                            @if ($earned['platform_fee_purchases'] > 0)
                                {{ $earned['platform_fee_purchases'] }} credit {{ Str::plural('purchase', $earned['platform_fee_purchases']) }} paid a platform fee{{ $showsFigures ? ' ('.Money::format($earned['platform_fee_cents']).' in all)' : '' }}.
                            @endif
                            @if ($earned['employer_fee_purchases'] > 0)
                                {{ $earned['employer_fee_purchases'] }} credit {{ Str::plural('purchase', $earned['employer_fee_purchases']) }} paid the monthly employer platform fee{{ $showsFigures ? ' ('.Money::format($earned['employer_fee_cents']).' in all)' : '' }}.
                            @endif
                            @if ($earned['bid_fee_years'] > 0)
                                <span data-earned-bid-fee>{{ $earned['bid_fee_years'] }} program {{ Str::plural('year', $earned['bid_fee_years']) }} of the BID annual program fee {{ $earned['bid_fee_years'] === 1 ? 'was' : 'were' }} paid in Stripe test mode{{ $showsFigures ? ' ('.Money::format($earned['bid_fee_cents']).' in all)' : '' }}.</span>
                            @endif
                        </p>
                    @endif
                    <p data-charges-now="{{ $earned['charges_now'] ? 'yes' : 'no' }}">
                        @if (\App\Support\BidProgramFee::charges())
                            <span data-bid-program-fee-status>The BID program fee is {{ \App\Support\BidProgramFee::status() }}.</span>
                        @endif
                        @if (\App\Support\EmployerPlatformFee::charges())
                            <span data-employer-fee-status>The employer platform fee is {{ \App\Support\EmployerPlatformFee::status() }}.</span>
                            New credit purchases can earn Local Goods money{{ \App\Support\MerchantFee::current()->isNone() ? '' : ', and so can sales, because the merchant fee is on too' }}.
                        @elseif ($earned['charges_now'])
                            A fee is switched on now, so new activity can earn Local Goods money.
                        @elseif ($hasEarned)
                            Otherwise Local Goods charges nothing: the platform fee and the merchant fee are both off now, so this is not ongoing income.
                        @else
                            Local Goods charges nothing: the platform fee and the merchant fee are both off, so it has earned nothing.
                        @endif
                    </p>
                </div>
            </section>
        </div>

        @if ($showsFigures)
            <section aria-labelledby="in-hand-heading" class="mt-14 border-2 border-frame bg-surface p-5 sm:p-8">
                <h3 id="in-hand-heading" class="statement-head text-xl font-bold tracking-tight">Money in hand</h3>
                <p class="mt-3 max-w-[52rem] text-sm text-muted">The money in the customer funds account, from the ledger: simulated and Stripe test-mode money together.</p>
                <x-rtable class="mt-6 max-w-3xl" label="Money in hand">
                    <table data-rtable="fit" class="statement text-left text-sm">
                        <caption class="sr-only">Money in hand: paid in, less paid out and refunded</caption>
                        <thead><tr><th scope="col" class="text-left">Line</th><th scope="col" class="text-right">Amount</th></tr></thead>
                        <tbody>
                            <tr><th scope="row" data-primary class="font-normal">Paid in by employers for credits</th><td data-label="Amount" class="tabular">{{ Money::format($now->creditsPaidInCents) }}</td></tr>
                            <tr><th scope="row" data-primary class="font-normal">Paid in as platform fees</th><td data-label="Amount" class="tabular">{{ Money::format($now->feesPaidInCents) }}</td></tr>
                            <tr><th scope="row" data-primary class="font-normal">Paid out to merchants</th><td data-label="Amount" class="tabular">−{{ Money::format($now->paidOutCents) }}</td></tr>
                            <tr><th scope="row" data-primary class="font-normal">Pulled back from merchants for refunds and reversals</th><td data-label="Amount" class="tabular">{{ Money::format($now->pulledBackCents) }}</td></tr>
                            <tr><th scope="row" data-primary class="font-normal">Refunded to employers</th><td data-label="Amount" class="tabular">−{{ Money::format($now->refundedCents) }}</td></tr>
                            <tr><th scope="row" data-primary class="font-normal">Swept to Local Goods Operating</th><td data-label="Amount" class="tabular" data-swept>−{{ Money::format($now->sweptCents) }}</td></tr>
                            <tr class="statement-total"><th scope="row" data-primary>Money in hand</th><td data-label="Amount" class="tabular" data-in-hand>{{ Money::format($now->inHandCents()) }}</td></tr>
                            <tr class="statement-after"><th scope="row" data-primary class="font-normal">Promised: customer funds plus earned money not yet swept</th><td data-label="Amount" class="tabular">{{ Money::format($now->promisedCents()) }}</td></tr>
                        </tbody>
                    </table>
                </x-rtable>
                <p class="mt-4 text-sm text-muted">Of that, Stripe test mode: <span class="ledger-num">{{ Money::format($now->stripeInCents) }}</span> paid in, <span class="ledger-num">{{ Money::format($now->stripeOutCents) }}</span> paid out.</p>
            </section>
        @endif
    </section>

    <section id="sweep" aria-labelledby="sweep-heading" class="rule-heavy scroll-mt-24 space-y-4 [&>p]:max-w-[52rem]">
        <h2 id="sweep-heading" class="stop-subtitle text-2xl">Moving earned money to Local Goods Operating</h2>
        <p>
            Money Local Goods has earned leaves the customer funds account in only one way: a sweep, decided by the platform admin with a reason,
            recorded and never edited. The solvency check refuses a sweep that would leave customer funds short.
        </p>
        @if (! $showsFigures)
            <p class="text-sm text-muted" data-sweep="words">Amounts are shown to Local Goods staff only. Only the platform admin can sweep.</p>
        @elseif ($sweepable === 0)
            <p class="ledger-note" data-sweep="nothing">
                <span class="font-semibold">Nothing to sweep.</span> Local Goods has no earned money left in the customer funds account,
                so every cent there belongs to employers, their staff or merchants.
            </p>
        @else
            <p data-sweep="available"><span class="font-semibold">Earned, not yet swept:</span> <span class="ledger-num" data-sweepable>{{ Money::format($sweepable) }}</span>
                ({{ $sweepChannel === 'stripe' ? 'swept by a Stripe test-mode transfer' : 'simulated, no real money' }}).</p>
            @if (! $maySweep)
                <p class="text-sm text-muted">Read-only: only the platform admin can sweep.</p>
            @elseif ($sweepReview === null)
                <form method="POST" action="{{ route('admin.operating.sweep.review') }}" data-busy="Reviewing…" class="space-y-3">
                    @csrf
                    <x-field name="amount" label="Amount in dollars" hint="At most the amount earned and not yet swept.">
                        <input id="amount" name="amount" type="text" inputmode="decimal" autocomplete="off" required value="{{ old('amount', Money::toDollars($sweepable)) }}"
                               class="w-40 rounded-lg border-2 border-edge bg-surface px-3 py-2 text-base tabular" aria-describedby="amount-hint @error('amount') amount-error @enderror" @error('amount') aria-invalid="true" @enderror>
                    </x-field>
                    <x-field name="reason" label="Reason" hint="Kept with the sweep for good. 5 to 500 characters.">
                        <textarea id="reason" name="reason" rows="2" required maxlength="500" class="w-full max-w-3xl rounded-lg border-2 border-edge bg-surface px-3 py-2 text-base"
                                  aria-describedby="reason-hint @error('reason') reason-error @enderror" @error('reason') aria-invalid="true" @enderror>{{ old('reason') }}</textarea>
                    </x-field>
                    <x-button type="submit">Review the sweep</x-button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.operating.sweep') }}" data-busy="Sweeping…" class="decision-panel max-w-3xl space-y-3 p-4 sm:p-5" data-sweep-review>
                    @csrf
                    <p><span class="font-semibold">Review:</span> move <span class="money">{{ Money::format($sweepReview['cents']) }}</span>
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

        <h3 class="statement-head pt-4 text-xl font-bold tracking-tight">Sweep history</h3>
        @if ($sweeps->isEmpty())
            <p class="text-sm text-muted" data-sweep-history="empty">No sweeps yet. Every one will be listed here, with its date, amount, reason and who decided it.</p>
        @elseif (! $showsFigures)
            <p class="text-sm" data-sweep-history="words">{{ $sweeps->count() }} {{ Str::plural('sweep', $sweeps->count()) }} recorded. Amounts and reasons are shown to Local Goods staff only.</p>
        @else
            <x-rtable label="Sweeps to Local Goods Operating">
                <table data-rtable="stack" class="w-full text-left text-sm" data-sweep-history="table">
                    <caption class="sr-only">Every sweep from the customer funds account to Local Goods Operating, newest first</caption>
                    <thead>
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
                                <td data-primary class="py-2.5 pr-4 [font-variant-numeric:tabular-nums]">{{ $dc($sweep->swept_at ?? $sweep->created_at) }}</td>
                                <td data-label="Amount" class="tabular py-2.5 pr-4 text-right">{{ Money::format($sweep->amount_cents) }}</td>
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

    <section id="solvency-check" aria-labelledby="check-heading" class="rule-heavy space-y-6">
        <h2 id="check-heading" class="stop-subtitle text-2xl">The solvency check</h2>

        @if ($run === null)
            <p class="ledger-note max-w-[52rem]" data-solvency="not-run">
                <span class="font-semibold">Not run yet.</span> It runs after every deploy, after every tick of the demo activity generator,
                and when the platform admin presses Run the check now.
            </p>
        @elseif ($run->passed)
            <p class="max-w-[52rem] border-t-[3px] border-ok bg-ok-bg px-4 py-3 font-semibold text-ok" data-solvency="pass">
                <span aria-hidden="true">✓</span> Pass: at every moment in the ledger's history, the money held covered every promise.
            </p>
        @else
            <section role="alert" aria-labelledby="fail-heading" class="border-t-[3px] border-danger bg-danger-bg p-5 text-danger" data-solvency="fail">
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
            <dl class="grid gap-x-6 gap-y-8 sm:grid-cols-3">
                <div class="rule-top">
                    <dt class="figure-label">Last run</dt>
                    <dd class="tabular mt-2 text-xl font-bold" data-last-run>{{ $dc($run->created_at) }}</dd>
                    <dd class="mt-1 text-sm text-muted">By {{ $run->triggerLabel() }}, in {{ number_format($run->duration_ms) }} ms.</dd>
                </div>
                <div class="rule-top">
                    <dt class="figure-label">What it checked</dt>
                    <dd class="tabular mt-2 text-xl font-bold" data-entries-checked>{{ number_format($run->entries_checked) }} ledger {{ Str::plural('entry', $run->entries_checked) }}</dd>
                    <dd class="mt-1 text-sm text-muted">At {{ number_format($run->moments_checked) }} {{ Str::plural('moment', $run->moments_checked) }}, one after each change, never just at the end.</dd>
                </div>
                <div class="rule-top">
                    <dt class="figure-label">Over</dt>
                    <dd class="tabular mt-2 text-xl font-bold" data-date-range>{{ $day($run->first_entry_at) }} to {{ $day($run->last_entry_at) }}</dd>
                    <dd class="mt-1 text-sm text-muted">The whole history, from the first ledger entry.</dd>
                </div>
            </dl>
        @endif

        <p class="border-t border-ink pt-3 text-sm" data-solvency-now="{{ $now->passes() ? 'pass' : 'fail' }}">
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

    <section aria-labelledby="meaning-heading" class="rule-heavy space-y-4 [&>p]:max-w-[52rem]">
        <h2 id="meaning-heading" class="stop-subtitle text-2xl">What the rule means</h2>
        <p>
            <span class="font-semibold">The rule:</span> the money employers have paid in, less what has been paid out to merchants and refunded to employers,
            must always cover every promise still open. The open promises are every employer's unspent credits, every employee's balance, everything owed to merchants,
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
        <div class="grid max-w-[60rem] gap-x-10 gap-y-8 pt-2 sm:grid-cols-2">
            <div class="rule-top">
                <h3 class="font-bold">What it makes sure of</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    <li>At every recorded moment, every promise had money behind it, and no account was below zero.</li>
                    <li>Simulated money never paid for a Stripe test-mode transfer.</li>
                    <li>The ledger agrees with the purchase, payout and refund records it was written from.</li>
                </ul>
            </div>
            <div class="rule-top">
                <h3 class="font-bold">What it doesn't cover</h3>
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
