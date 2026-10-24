@php use App\Support\Money; @endphp

<x-app-shell title="My wallet" active="wallet"
             :context="$employee->employer->name.' · Golden Triangle BID'">
<div class="mx-auto max-w-xl space-y-6">
    {{-- Confirmation of the redemption just made, announced to screen readers. Show it at the counter. --}}
    <div aria-live="polite">
        @if ($result)
            @php
                $approved = $result->isApproved();
                $when = $result->created_at->setTimezone('America/New_York');
            @endphp
            <section aria-labelledby="confirmation-heading" tabindex="-1" data-flash @class([
                'rounded-3xl border-2 p-6 text-center sm:p-8',
                'border-ok bg-ok-bg text-ok' => $approved,
                'border-hold bg-hold-bg text-hold' => ! $approved,
            ])>
                {{-- A live clock and a gentle pulse show the merchant this is the live app, not a screenshot. --}}
                <span class="relative mx-auto grid size-16 place-items-center">
                    @if ($approved)
                        <span class="absolute inset-0 rounded-full bg-ok opacity-30 motion-safe:animate-ping" aria-hidden="true"></span>
                    @endif
                    <span @class(['relative grid size-16 place-items-center rounded-full text-on-night', 'bg-ok' => $approved, 'bg-hold' => ! $approved]) aria-hidden="true">
                        @if ($approved)
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="size-9"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                        @else
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="size-9"><circle cx="12" cy="12" r="8.25"/><path d="M12 7.5V12l3 2"/></svg>
                        @endif
                    </span>
                </span>
                <h2 id="confirmation-heading" class="mt-3 font-display text-4xl font-semibold">{{ $approved ? 'Approved' : ($result->isRejected() ? ($result->cancelledLabel() ?? 'Denied') : 'Under review') }}</h2>
                @if ($approved)
                    <p class="mt-2 inline-flex items-center gap-2 rounded-full bg-surface px-3 py-1 text-sm font-semibold text-ink">
                        <span class="relative flex size-2.5" aria-hidden="true">
                            <span class="absolute inline-flex size-full rounded-full bg-ok opacity-60 motion-safe:animate-ping"></span>
                            <span class="relative inline-flex size-2.5 rounded-full bg-ok"></span>
                        </span>
                        Live · <span class="tabular" role="timer" aria-live="off" aria-label="Current time" data-live-clock>{{ now()->setTimezone('America/New_York')->format('g:i:s A') }}</span>
                    </p>
                @endif
                <p class="tabular mt-2 font-display text-5xl font-semibold text-ink">{{ Money::format($result->amount_cents) }}</p>
                <p class="mt-1 text-lg font-semibold text-ink">{{ $result->merchant->name }}</p>
                @if ($for = $result->itemsSummary())
                    <p class="mt-1 text-ink" data-items>{{ $for }}</p>
                @endif
                @include('partials.tax-breakdown', ['tax' => $result->taxBreakdown(), 'class' => 'mx-auto mt-3 max-w-xs text-left text-sm text-ink'])
                <dl class="mx-auto mt-4 grid max-w-xs grid-cols-2 gap-2 text-left text-sm text-ink">
                    <dt class="text-muted">Confirmation</dt><dd class="tabular text-right font-semibold">{{ $result->reference() }}</dd>
                    <dt class="text-muted">Time</dt><dd class="text-right font-semibold">{{ $when->format('M j, g:i A') }}</dd>
                    <dt class="text-muted">How</dt><dd class="text-right font-semibold" data-channel>{{ $result->channelLabel() }}</dd>
                </dl>
                @if ($result->isInPerson())
                    <p class="mt-2"><x-badge>In person</x-badge></p>
                @endif
                <p class="mx-auto mt-4 max-w-sm">
                    @if ($result->isInPerson() && $approved)
                        Done. {{ $result->merchant->name }} confirmed it at the counter and sees it on their Local Goods dashboard.
                    @elseif ($result->isRejected())
                        {{ $result->decision?->credit_returned_to === 'employer' ? 'The credits went back to your employer.' : 'Your credits are back in your balance.' }}
                    @elseif ($result->isInPerson())
                        Your payment is being held for a quick review. Your credits are set aside, and come back to you if it isn't approved. If you pay another way, the shop can cancel this payment and your credits come back.
                    @elseif ($approved)
                        Show this screen at the counter. The clock is live, so it can't be a saved screenshot.
                        {{ $result->merchant->name }} also sees this redemption on their Local Goods dashboard.
                    @else
                        Redemption Fraud Detection asked for a quick check by a person at the BID before the merchant is paid.
                        Your credits are reserved meanwhile, and come back to you if it isn't approved.
                    @endif
                </p>
                <x-button :href="route('employee.wallet')" variant="secondary" class="mt-5 bg-surface">Done</x-button>
            </section>
        @endif
    </div>

    <section aria-labelledby="balance-heading" class="relative overflow-hidden rounded-3xl bg-night p-6 text-on-night shadow-[0_12px_30px_-12px_rgb(18_24_32/0.6)] sm:p-8">
        <div class="flex items-start justify-between gap-4">
            <h1 id="balance-heading" class="font-sans text-sm font-semibold uppercase tracking-widest text-night-muted">Available balance</h1>
            <span class="font-display text-sm font-semibold whitespace-nowrap text-on-night">Local Goods</span>
        </div>
        <p class="tabular mt-3 font-display text-6xl font-semibold tracking-tight text-night-accent" data-testid="balance">{{ Money::format($balance) }}</p>
        <p class="mt-4 text-sm text-night-muted">{{ Money::format($employee->employer->monthly_allowance_cents) }} added each month by {{ $employee->employer->name }}</p>
        <p class="mt-1 text-sm text-night-muted">Demo account</p>
    </section>

    {{-- Two ways to pay (ADR 0026). Real tabs with arrow keys when JavaScript runs; plain links to ?tab= without it. --}}
    <div id="wallet-tabs" class="scroll-mt-24" data-tabs>
        <div role="tablist" aria-label="How to pay" class="grid grid-cols-2 gap-1 rounded-2xl border-2 border-line bg-surface p-1">
            @foreach (['to-go' => 'Order to go', 'in-person' => 'Pay in person'] as $key => $label)
                <a role="tab" id="tab-{{ $key }}" href="{{ route('employee.wallet', ['tab' => $key]) }}#wallet-tabs"
                   aria-controls="panel-{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" tabindex="{{ $tab === $key ? '0' : '-1' }}"
                   class="rounded-xl px-3 py-2.5 text-center font-semibold text-primary hover:bg-primary-tint aria-selected:bg-primary aria-selected:text-on-primary">{{ $label }}</a>
            @endforeach
        </div>

        <div role="tabpanel" id="panel-to-go" aria-labelledby="tab-to-go" tabindex="0" class="mt-6 space-y-6" @if ($tab !== 'to-go') hidden @endif>
        <x-card as="section" id="redeem" aria-labelledby="redeem-heading" class="scroll-mt-24">
            <h2 id="redeem-heading" class="text-2xl font-semibold text-primary">Redeem credits</h2>
            <p class="mt-1 text-sm text-muted">Every redemption is checked by Redemption Fraud Detection before the merchant is paid.</p>
            <p class="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted">
                <x-money-mode />
                {{ \App\Support\StripeMode::active()
                    ? 'Merchants are paid through Stripe (test mode) once a redemption clears. Your credits never touch a card.'
                    : 'Merchant payouts are simulated on this server: no money moves.' }}
            </p>

            @if ($suspicious)
                <p class="mt-4 rounded-xl bg-hold-bg p-3 text-sm text-hold">
                    Pre-filled with a suspicious pattern: the whole balance, as a round number. Confirm it to watch Redemption Fraud Detection hold it.
                </p>
            @endif

            @if ($menuMerchant === null)
                {{-- Step 1: choose where. "Redeem here" on a merchant card skips straight to step 2. --}}
                <form method="GET" action="{{ route('employee.wallet') }}#redeem" class="mt-5 space-y-4">
                    <div>
                        <label for="merchant_id" class="block font-semibold">Merchant</label>
                        <select id="merchant_id" name="merchant" required
                                class="mt-1.5 block w-full rounded-xl border-2 border-muted bg-surface px-3 py-3 text-ink focus-visible:border-primary"
                                @error('merchant_id') aria-invalid="true" aria-describedby="merchant-error" @enderror>
                            <option value="">Choose a merchant</option>
                            @foreach ($merchants as $merchant)
                                <option value="{{ $merchant->id }}">{{ $merchant->name }} ({{ $merchant->category }})</option>
                            @endforeach
                        </select>
                        @error('merchant_id') <p id="merchant-error" class="mt-1.5 text-sm font-semibold text-hold">{{ $message }}</p> @enderror
                    </div>
                    <x-button type="submit" size="lg" class="w-full">Show menu</x-button>
                    @cannot('redeem')
                        <p class="text-sm text-muted">Read-only: judges can see the menus but can't make redemptions.</p>
                    @endcannot
                </form>
            @else
                @php $quantities = old('items', []); @endphp
                {{-- Step 2: pick from the menu (display only: Redemption Fraud Detection sees just the total, ADR 0023). --}}
                <form method="POST" action="{{ route('employee.redeem') }}" data-busy="Checking with Redemption Fraud Detection…" data-basket class="mt-5 space-y-5">
                    @csrf
                    <input type="hidden" name="merchant_id" value="{{ $menuMerchant->id }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <p class="text-lg font-semibold">At {{ $menuMerchant->name }}</p>
                        <a href="{{ route('employee.wallet') }}#redeem" class="tap-target text-sm font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Change merchant</a>
                    </div>

                    @include('employee.partials.basket-fields', ['prefix' => '', 'menu' => $menu, 'quantities' => $quantities, 'amount' => old('amount', $prefill['amount'])])

                    @can('redeem')
                        <x-button type="submit" variant="accent" size="lg" class="w-full">Confirm redemption</x-button>
                    @else
                        <x-button type="submit" variant="accent" size="lg" class="w-full" disabled aria-describedby="read-only-note">Confirm redemption</x-button>
                        <p id="read-only-note" class="text-sm text-muted">Read-only: judges can't make redemptions.</p>
                    @endcan
                </form>
            @endif

            <x-button variant="danger" size="sm" :href="route('employee.wallet', ['suspicious' => 1]).'#redeem'" class="mt-3 w-full">
                Try a suspicious redemption
            </x-button>
        </x-card>

        <section id="browse" aria-labelledby="browse-heading" class="scroll-mt-24">
            <h2 id="browse-heading" class="text-2xl font-semibold text-primary">Where you can spend</h2>
            <p class="mt-1 text-sm text-muted">Independent businesses in the Golden Triangle BID. All are fictional in this demo.</p>

            <form method="GET" action="{{ route('employee.wallet') }}#browse" role="search" class="mt-3 flex gap-2">
                @if ($category !== '')<input type="hidden" name="category" value="{{ $category }}">@endif
                <label for="merchant-search" class="sr-only">Search merchants</label>
                <input id="merchant-search" name="q" type="search" value="{{ $search }}" placeholder="Search by name or street"
                       class="min-w-0 flex-1 rounded-xl border-2 border-muted bg-surface px-3 py-2.5 focus-visible:border-primary">
                <x-button type="submit" size="sm">Search</x-button>
            </form>

            <ul class="mt-3 flex flex-wrap gap-2" aria-label="Filter by category">
                @foreach (collect(['' => 'All'])->merge($categories->mapWithKeys(fn ($c) => [$c => ucfirst($c)])) as $value => $label)
                    <li>
                        <a href="{{ route('employee.wallet', array_filter(['category' => $value, 'q' => $search])) }}#browse"
                           @if ($category === (string) $value) aria-current="true" @endif
                           class="tap-target inline-block rounded-full border-2 border-primary px-3.5 py-1.5 text-sm font-semibold text-primary hover:bg-primary-tint aria-[current=true]:bg-primary aria-[current=true]:text-on-primary">{{ $label }}</a>
                    </li>
                @endforeach
            </ul>

            @if ($browse->isEmpty())
                <x-card class="mt-3 text-muted">
                    No merchants match{{ $search !== '' ? ' "'.$search.'"' : '' }}{{ $category !== '' ? ' in '.$category : '' }}.
                    <a href="{{ route('employee.wallet') }}#browse" class="font-semibold text-primary underline underline-offset-4">Show all merchants</a>
                </x-card>
            @else
                <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach ($browse as $merchant)
                        <x-card as="li" padding="p-4 pl-6" class="merchant-rail flex flex-col">
                            <p class="font-semibold">{{ $merchant->name }}</p>
                            <p class="text-sm text-muted"><span class="capitalize">{{ $merchant->category }}</span> · {{ $merchant->address }}</p>
                            <a href="{{ route('employee.wallet', ['merchant' => $merchant->id]) }}#redeem"
                               class="tap-target mt-2 min-h-6 self-start text-sm font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">
                                Redeem here<span class="sr-only"> at {{ $merchant->name }}</span>
                            </a>
                        </x-card>
                    @endforeach
                </ul>
            @endif
        </section>
        </div>

        <div role="tabpanel" id="panel-in-person" aria-labelledby="tab-in-person" tabindex="0" class="mt-6" @if ($tab !== 'in-person') hidden @endif>
            @include('employee.partials.pay-in-person')
        </div>
    </div>

    <section aria-labelledby="recent-heading">
        <h2 id="recent-heading" class="text-2xl font-semibold text-primary">Recent redemptions</h2>
        <x-card as="ul" padding="p-0" class="mt-3 divide-y divide-line/70">
            @forelse ($recent as $redemption)
                <li class="flex items-center justify-between gap-3 px-5 py-4">
                    <div class="min-w-0">
                        <p class="font-semibold break-words">{{ $redemption->merchant->name }}</p>
                        @if ($for = $redemption->itemsSummary())
                            <p class="text-sm break-words" data-items>{{ $for }}</p>
                        @endif
                        @include('partials.tax-breakdown', ['tax' => $redemption->taxBreakdown(), 'compact' => true, 'class' => 'text-sm text-muted'])
                        <p class="text-sm text-muted">{{ $redemption->created_at->setTimezone('America/New_York')->format('M j, g:i A') }} <span data-channel>· {{ $redemption->channelLabel() }}</span></p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="tabular font-semibold">{{ Money::format($redemption->amount_cents) }}</p>
                        <x-status-badge :redemption="$redemption" class="mt-1" />
                        @if ($redemption->isRejected())
                            <p class="mt-1 max-w-36 text-xs text-muted">{{ $redemption->isCancelled() ? 'Cancelled, credits returned' : ($redemption->decision?->credit_returned_to === 'employer' ? 'Credit returned to your employer' : 'Credit back in your balance') }}</p>
                        @endif
                    </div>
                </li>
            @empty
                <li class="px-5 py-4 text-muted">No redemptions yet.</li>
            @endforelse
        </x-card>
    </section>

    <section id="history" aria-labelledby="history-heading" class="scroll-mt-24">
        <h2 id="history-heading" class="text-2xl font-semibold text-primary">Transaction history</h2>
        <p class="mt-1 text-sm text-muted">Every credit in and out of your wallet, newest first, from the Local Goods ledger.</p>
        <x-card as="ul" padding="p-0" class="mt-3 divide-y divide-line/70">
            @forelse ($history as $row)
                <li class="flex items-start justify-between gap-3 px-5 py-3">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $row['entry']->description }}</p>
                        @if ($row['entry']->type === \App\Models\CreditLedgerEntry::TYPE_REDEMPTION && $row['entry']->redemption)
                            <p class="text-sm text-muted" data-channel>{{ $row['entry']->redemption->channelLabel() }}</p>
                        @endif
                        <p class="text-sm text-muted">{{ $row['entry']->created_at->setTimezone('America/New_York')->format('M j, Y, g:i A') }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p @class(['tabular font-semibold', 'text-ok' => $row['entry']->amount_cents > 0])>
                            {{ $row['entry']->amount_cents > 0 ? '+' : '' }}{{ Money::format($row['entry']->amount_cents) }}
                        </p>
                        <p class="tabular text-sm text-muted">Balance {{ Money::format($row['balance_after']) }}</p>
                    </div>
                </li>
            @empty
                <li class="px-5 py-4 text-muted">No transactions yet. Your employer's first allowance will show here.</li>
            @endforelse
        </x-card>
        @if (! $showAllHistory && $historyCount > $history->count())
            <p class="mt-3"><a href="{{ route('employee.wallet', ['history' => 1]) }}#history" class="tap-target font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Show all {{ $historyCount }} transactions</a></p>
        @endif
    </section>
</div>
</x-app-shell>
