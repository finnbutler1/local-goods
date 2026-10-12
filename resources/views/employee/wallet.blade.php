@php use App\Support\Money; @endphp

<x-app-shell title="My wallet" active="wallet"
             :context="$employee->employer->name.' · Golden Triangle BID'">
<div class="mx-auto max-w-xl space-y-6">
    {{-- Result of the last redemption, announced to screen readers --}}
    <div aria-live="polite">
        @if ($result)
            @if ($result->isApproved())
                <div class="rounded-2xl border-2 border-ok bg-ok-bg p-5 text-ok">
                    <p class="flex items-center gap-2 font-display text-2xl font-semibold">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="size-6" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                        Approved
                    </p>
                    <p class="mt-1">{{ Money::format($result->amount_cents) }} at {{ $result->merchant->name }}. Show this screen at the counter.</p>
                </div>
            @else
                <div class="rounded-2xl border-2 border-hold bg-hold-bg p-5 text-hold">
                    <p class="font-display text-2xl font-semibold">Under review</p>
                    <p class="mt-1">{{ Money::format($result->amount_cents) }} at {{ $result->merchant->name }} needs a quick check by a person before the merchant is paid. Your credits are reserved in the meantime.</p>
                </div>
            @endif
        @endif
    </div>

    <section aria-labelledby="balance-heading" class="relative overflow-hidden rounded-3xl bg-forest p-6 text-cream shadow-[0_12px_30px_-12px_rgb(31_61_43/0.6)] sm:p-8">
        <div class="awning absolute inset-x-0 top-0 h-1.5 opacity-60" aria-hidden="true"></div>
        <div class="flex items-start justify-between gap-4">
            <h1 id="balance-heading" class="font-sans text-sm font-semibold uppercase tracking-widest text-sage">Available balance</h1>
            <span class="font-display text-sm font-semibold text-cream">Local Goods</span>
        </div>
        <p class="tabular mt-3 font-display text-6xl font-semibold tracking-tight text-amber" data-testid="balance">{{ Money::format($balance) }}</p>
        <p class="mt-4 text-sm text-sage">{{ Money::format($employee->employer->monthly_allowance_cents) }} added each month by {{ $employee->employer->name }}</p>
        <p class="mt-1 text-sm text-sage">Demo employee: <span class="font-semibold text-cream">{{ $employee->display_name }}</span></p>
    </section>

    <x-card as="section" id="redeem" aria-labelledby="redeem-heading" class="scroll-mt-24">
        <h2 id="redeem-heading" class="text-2xl font-semibold text-forest">Redeem credits</h2>
        <p class="mt-1 text-sm text-muted">Every redemption is checked by Redemption Fraud Detection before the merchant is paid.</p>

        @if ($suspicious)
            <p class="mt-4 rounded-xl bg-hold-bg p-3 text-sm text-hold">
                Pre-filled with a suspicious pattern: the whole balance, as a round number. Confirm it to watch Redemption Fraud Detection hold it.
            </p>
        @endif

        <form method="POST" action="{{ route('employee.redeem') }}" class="mt-5 space-y-5">
            @csrf
            <div>
                <label for="merchant_id" class="block font-semibold">Merchant</label>
                <select id="merchant_id" name="merchant_id" required
                        class="mt-1.5 block w-full rounded-xl border-2 border-muted bg-paper px-3 py-3 text-ink focus-visible:border-forest"
                        @error('merchant_id') aria-invalid="true" aria-describedby="merchant-error" @enderror>
                    <option value="">Choose a merchant</option>
                    @foreach ($merchants as $merchant)
                        <option value="{{ $merchant->id }}" @selected((string) old('merchant_id', $prefill['merchant_id']) === (string) $merchant->id)>
                            {{ $merchant->name }} ({{ $merchant->category }})
                        </option>
                    @endforeach
                </select>
                @error('merchant_id') <p id="merchant-error" class="mt-1.5 text-sm font-semibold text-hold">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="amount" class="block font-semibold">Amount</label>
                <div class="mt-1.5 flex items-center rounded-xl border-2 border-muted bg-paper focus-within:border-forest focus-within:outline-3 focus-within:outline-offset-2 focus-within:outline-amber">
                    <span class="pl-3 font-display text-lg text-muted" aria-hidden="true">$</span>
                    <input id="amount" name="amount" type="text" inputmode="decimal" autocomplete="off" required
                           value="{{ old('amount', $prefill['amount']) }}" placeholder="0.00"
                           class="tabular w-full rounded-xl bg-transparent px-2 py-3 text-lg text-ink focus:outline-none"
                           aria-describedby="amount-hint @error('amount') amount-error @enderror"
                           @error('amount') aria-invalid="true" @enderror>
                </div>
                <p id="amount-hint" class="mt-1.5 text-sm text-muted">In dollars, for example 12.50</p>
                @error('amount') <p id="amount-error" class="mt-1.5 text-sm font-semibold text-hold">{{ $message }}</p> @enderror
            </div>

            <x-button type="submit" variant="accent" size="lg" class="w-full">Confirm redemption</x-button>
        </form>

        <x-button variant="danger" size="sm" :href="route('employee.wallet', ['suspicious' => 1]).'#redeem'" class="mt-3 w-full">
            Try a suspicious redemption
        </x-button>
    </x-card>

    <section aria-labelledby="recent-heading">
        <h2 id="recent-heading" class="text-2xl font-semibold text-forest">Recent redemptions</h2>
        <x-card as="ul" padding="p-0" class="mt-3 divide-y divide-line/70">
            @forelse ($recent as $redemption)
                <li class="flex items-center justify-between gap-3 px-5 py-4">
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ $redemption->merchant->name }}</p>
                        <p class="text-sm text-muted">{{ $redemption->created_at->setTimezone('America/New_York')->format('M j, g:i A') }}</p>
                    </div>
                    <div class="text-right">
                        <p class="tabular font-semibold">{{ Money::format($redemption->amount_cents) }}</p>
                        <x-status-badge :redemption="$redemption" class="mt-1" />
                    </div>
                </li>
            @empty
                <li class="px-5 py-4 text-muted">No redemptions yet.</li>
            @endforelse
        </x-card>
    </section>
</div>
</x-app-shell>
