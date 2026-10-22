@php
    use App\Enums\Occasion;
    use App\Support\Money;
    use App\Support\PlatformFee;

    $input = 'block w-full rounded-xl border-2 bg-paper px-3 py-3 focus-visible:border-forest';
    $readOnly = Gate::denies('manage-employer-program');
    $link = 'font-semibold text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest';
@endphp

<x-app-shell title="Employer dashboard" active="dashboard"
             :context="($employer?->name ?? 'Employer').' · Golden Triangle BID'">
@if ($employer === null)
    <x-card as="section" aria-labelledby="empty-heading" class="border-2 border-dashed border-line ring-0! text-center" padding="px-6 py-12">
        <h1 id="empty-heading" class="text-2xl font-semibold text-forest">No employer program is set up yet</h1>
        <p class="mx-auto mt-2 max-w-md text-muted">Once your company joins Local Goods, your budget, credits and your team's neighborhood visits appear here.</p>
    </x-card>
@else
<div class="space-y-10">
    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Employer dashboard · {{ $employer->name }}</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Your team got out into the neighborhood.</h1>
        <p class="mt-3 text-lg text-muted">
            {{ $metrics['month']->format('F') }} so far: who used their perk, where they went, and how much stayed with independent Golden Triangle businesses.
            Every number is worked out from the credit ledger, and each one says how.
        </p>
    </header>

    @if ($readOnly)
        <p id="read-only-note" class="rounded-2xl bg-cream-deep p-4 text-sm">
            <span class="font-semibold">Read-only view.</span> Judges can look at every form here but can't buy, pay or give credits.
        </p>
    @endif

    {{-- Engagement --}}
    <section aria-labelledby="engagement-heading" class="space-y-4">
        <h2 id="engagement-heading" class="text-2xl font-semibold text-forest">This month in the neighborhood</h2>

        @php
            $tiles = [
                [
                    'label' => 'Employees who got out',
                    'value' => number_format($metrics['redeemers']),
                    'unit' => Str::plural('employee', $metrics['redeemers']).' got out',
                    'detail' => $metrics['participation_pct'].'% of enrolled'.($metrics['staff_pct'] !== null ? ' · '.$metrics['staff_pct'].'% of all '.$metrics['staff'].' staff' : ''),
                    'how' => 'Employees with at least one cleared redemption this month. The first share divides by the '.$metrics['enrolled'].' enrolled in the program, the second by all '.($metrics['staff'] ?? $metrics['enrolled']).' staff. Held and denied redemptions don\'t count.',
                ],
                [
                    'label' => 'Visits out of the office',
                    'value' => number_format($metrics['visits']),
                    'detail' => 'cleared redemptions this month',
                    'how' => 'Each cleared redemption is one visit to a local business. Two purchases on the same day count as two visits.',
                ],
                [
                    'label' => 'Local businesses visited',
                    'value' => $metrics['businesses'].' of '.$metrics['participating'],
                    'unit' => 'participating '.Str::plural('business', $metrics['participating']),
                    'detail' => $metrics['first_visits'].' first-time '.Str::plural('visit', $metrics['first_visits']),
                    'how' => 'Different businesses with at least one cleared redemption this month, out of every business taking part in the BID. A first-time visit is someone going to a business they had never redeemed at before.',
                ],
                [
                    'label' => 'Spent locally',
                    'value' => Money::format($metrics['spent_cents']),
                    'detail' => $metrics['held_count'] > 0 ? Money::format($metrics['held_cents']).' more waiting for review' : 'at independent merchants',
                    'how' => 'The total of cleared redemptions this month. Redemptions held by Redemption Fraud Detection are left out until a person at the BID clears them.',
                ],
            ];
        @endphp
        <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($tiles as $tile)
                <x-card class="flex flex-col">
                    <dt class="text-sm font-semibold text-muted">{{ $tile['label'] }}</dt>
                    <dd class="mt-2 text-forest">
                        <span class="tabular font-display text-4xl font-semibold">{{ $tile['value'] }}</span>
                        @isset($tile['unit'])<span class="font-semibold"> {{ $tile['unit'] }}</span>@endisset
                    </dd>
                    <dd class="mt-1 text-sm text-muted">{{ $tile['detail'] }}</dd>
                    <dd class="mt-3 border-t border-line/70 pt-2 text-sm">
                        <details>
                            <summary class="cursor-pointer font-semibold text-forest">How we count this</summary>
                            <p class="mt-1 text-muted">{{ $tile['how'] }}</p>
                        </details>
                    </dd>
                </x-card>
            @endforeach
        </dl>

        <div class="grid gap-3 lg:grid-cols-3">
            <x-card as="section" aria-labelledby="trend-heading" class="lg:col-span-2">
                @php
                    $weeks = $metrics['weeks'];
                    $max = max(1, max(array_column($weeks, 'visits')));
                @endphp
                <h3 id="trend-heading" class="text-xl font-semibold">Visits per week, last {{ count($weeks) }} weeks</h3>
                <p class="mt-1 text-sm text-muted">Monday to Sunday. The last bar is this week so far.</p>

                @if (array_sum(array_column($weeks, 'visits')) === 0)
                    <p class="mt-6 rounded-xl bg-cream-deep p-4 text-muted">No visits yet. Once your team redeems credits, each week's visits show here.</p>
                @else
                    {{-- Bars are hidden from screen readers: the sentence under them sums them up and
                         "Show as a table" has every week's numbers. Hover a bar for its week. --}}
                    <div class="mt-6 flex h-44 items-end gap-1.5 border-b border-muted/40 sm:gap-3" aria-hidden="true">
                        @foreach ($weeks as $week)
                            <div class="group relative flex h-full flex-1 items-end justify-center">
                                <div class="w-full max-w-12 rounded-t-[4px] bg-forest transition-colors group-hover:bg-forest-soft"
                                     style="height: {{ max(2, round(100 * $week['visits'] / $max)) }}%"></div>
                                <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 hidden w-max -translate-x-1/2 rounded-lg bg-ink px-2.5 py-1.5 text-xs text-cream shadow group-hover:block">
                                    <span class="font-semibold">Week of {{ $week['start']->format('M j') }}</span><br>
                                    {{ $week['visits'] }} {{ Str::plural('visit', $week['visits']) }} · {{ Money::format($week['cents']) }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1.5 flex gap-1.5 text-center text-xs text-muted sm:gap-3" aria-hidden="true">
                        @foreach ($weeks as $i => $week)
                            <span class="flex-1">{{ $i % 2 === count($weeks) % 2 ? '' : $week['start']->format('M j') }}</span>
                        @endforeach
                    </div>
                    <p class="mt-3 text-sm">
                        <span class="font-semibold">{{ $weeks[count($weeks) - 1]['visits'] }}</span> visits so far this week,
                        out of a peak of <span class="font-semibold">{{ $max }}</span>.
                    </p>
                @endif

                <details class="mt-3 text-sm">
                    <summary class="cursor-pointer font-semibold text-forest">Show as a table</summary>
                    <table class="mt-2 w-full text-left">
                        <caption class="sr-only">Cleared visits and dollars spent locally per week</caption>
                        <thead class="text-muted"><tr><th scope="col" class="py-1 font-semibold">Week of</th><th scope="col" class="py-1 text-right font-semibold">Visits</th><th scope="col" class="py-1 text-right font-semibold">Spent locally</th></tr></thead>
                        <tbody class="tabular divide-y divide-line/70">
                            @foreach ($weeks as $week)
                                <tr><th scope="row" class="py-1 font-normal">{{ $week['start']->format('M j') }}</th><td class="py-1 text-right">{{ $week['visits'] }}</td><td class="py-1 text-right">{{ Money::format($week['cents']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="mt-2 text-muted">How we count this: cleared redemptions by the week they happened, in Washington, DC time.</p>
                </details>
            </x-card>

            <x-card as="section" aria-labelledby="categories-heading">
                <h3 id="categories-heading" class="text-xl font-semibold">Top categories</h3>
                <p class="mt-1 text-sm text-muted">By dollars spent locally this month.</p>
                @if ($metrics['categories'] === [])
                    <p class="mt-4 rounded-xl bg-cream-deep p-4 text-muted">Nothing spent yet this month.</p>
                @else
                    @php $top = max(1, $metrics['categories'][0]['cents']); @endphp
                    <ol class="mt-4 space-y-3">
                        @foreach (array_slice($metrics['categories'], 0, 5) as $category)
                            <li>
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="font-semibold capitalize">{{ $category['category'] }}</span>
                                    <span class="tabular text-sm">{{ Money::format($category['cents']) }} · {{ $category['visits'] }} {{ Str::plural('visit', $category['visits']) }}</span>
                                </div>
                                <div class="mt-1 h-2 rounded-full bg-cream-deep" aria-hidden="true">
                                    <div class="h-2 rounded-full bg-forest" style="width: {{ max(2, round(100 * $category['cents'] / $top)) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
                <details class="mt-4 text-sm">
                    <summary class="cursor-pointer font-semibold text-forest">How we count this</summary>
                    <p class="mt-1 text-muted">Cleared redemptions this month, grouped by the merchant's category and added up.</p>
                </details>
            </x-card>
        </div>
    </section>

    {{-- Credits --}}
    <section id="credits" aria-labelledby="credits-heading" class="scroll-mt-24 space-y-4">
        <h2 id="credits-heading" class="text-2xl font-semibold text-forest">Credits</h2>

        <dl class="grid gap-3 sm:grid-cols-3">
            <x-card tone="forest">
                <dt class="text-sm font-semibold text-sage">Ready to give</dt>
                <dd class="tabular mt-2 font-display text-4xl font-semibold text-amber">{{ Money::format($balance) }}</dd>
                <dd class="mt-1 text-sm text-sage">Paid credits not yet given to employees</dd>
                @if ($returned['count'] > 0)
                    <dd class="mt-2 border-t border-sage/40 pt-2 text-sm text-cream" data-returned-credit="{{ $returned['cents'] }}">
                        @if ($returned['cents'] <= $balance)
                            Includes {{ Money::format($returned['cents']) }} returned from {{ $returned['count'] }} denied {{ Str::plural('redemption', $returned['count']) }}.
                        @else
                            {{ Money::format($returned['cents']) }} came back from {{ $returned['count'] }} denied {{ Str::plural('redemption', $returned['count']) }}; some of it has been given out again.
                        @endif
                        <a href="#credit-activity" class="font-semibold underline underline-offset-4">See returned credit</a>
                    </dd>
                @endif
            </x-card>
            <x-card>
                <dt class="text-sm font-semibold text-muted">Pending payment</dt>
                <dd class="tabular mt-2 font-display text-4xl font-semibold text-forest">{{ Money::format($pendingCents) }}</dd>
                <dd class="mt-1 text-sm text-muted">Can't be given out until paid</dd>
            </x-card>
            <x-card>
                <dt class="text-sm font-semibold text-muted">Given out this month</dt>
                <dd class="tabular mt-2 font-display text-4xl font-semibold text-forest">{{ Money::format($allocatedThisMonth) }}</dd>
                <dd class="mt-1 text-sm text-muted">
                    @if ($remainingBudget === null)
                        No monthly budget set
                    @else
                        {{ Money::format($remainingBudget) }} left of the {{ Money::format($employer->monthly_budget_cents) }} budget
                    @endif
                </dd>
            </x-card>
        </dl>

        <div class="grid gap-3 lg:grid-cols-2">
            @php
                $buying = $buy['purchase'];
                // Only when the platform fee is on (ADR 0021); at 0 nothing here mentions fees.
                $feeLine = fn ($p) => $p->platform_fee_cents > 0
                    ? 'Credits '.Money::format($p->amount_cents).' + platform fee '.Money::format($p->platform_fee_cents).' = total '.Money::format($p->totalCents())
                    : null;
            @endphp
            <x-card as="section" id="buy" aria-labelledby="buy-heading" @class(['scroll-mt-24 min-w-0', 'lg:col-span-2' => $buy['state'] === 'paying'])>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 id="buy-heading" class="text-xl font-semibold">Buy credits</h3>
                    <x-money-mode />
                </div>

                @if ($buy['state'] === 'paying')
                    {{-- Stripe's embedded Checkout (resources/js/checkout.js). The credits unlock only when Stripe's webhook confirms. --}}
                    <p class="mt-2">
                        Paying for <span class="font-semibold">{{ $buying->reference }}</span>:
                        <span class="tabular font-semibold">{{ Money::format($buying->amount_cents) }}</span> in credits.
                    </p>
                    @if ($line = $feeLine($buying))
                        <p class="tabular mt-1 font-semibold" data-fee-breakdown>{{ $line }}</p>
                    @endif
                    <p id="test-card-hint" class="mt-3 rounded-xl border-2 border-amber bg-cream-deep p-3 text-sm">
                        <strong>Test mode:</strong> use card <span class="tabular font-mono">4000 0000 0000 0077</span>, any future date, any CVC.
                        No real money moves. Stripe holds the money, not Local Goods.
                    </p>
                    <div class="mt-4 w-full min-w-0 overflow-hidden rounded-xl" data-embedded-checkout aria-label="Stripe payment form, test mode"
                         role="region" aria-describedby="test-card-hint"
                         data-stripe-key="{{ config('services.stripe.key') }}"
                         data-secret-url="{{ route('employer.purchases.checkout', $buying) }}"
                         data-csrf="{{ csrf_token() }}">
                        <p class="p-4 text-muted" data-checkout-loading>Loading Stripe's payment form…</p>
                    </div>
                    <noscript>
                        <p class="mt-3 rounded-xl bg-cream-deep p-3 text-sm">Paying by card needs JavaScript, because the card form is Stripe's. Turn it on and reload this page.</p>
                    </noscript>
                    <div hidden tabindex="-1" role="alert" data-checkout-error class="mt-4 rounded-xl border-2 border-hold bg-hold-bg p-4 text-hold">
                        <p class="font-semibold" data-checkout-error-text>Payment didn't start. Nothing was charged.</p>
                        <form method="POST" action="{{ route('employer.purchases.pay', $buying) }}" data-busy="Starting again…" class="mt-3">
                            @csrf
                            <x-button type="submit" size="sm">Try again</x-button>
                        </form>
                    </div>
                    <form method="POST" action="{{ route('employer.purchases.cancel', $buying) }}" data-busy="Cancelling…" class="mt-4">
                        @csrf
                        <x-button type="submit" variant="secondary" size="sm">Cancel payment</x-button>
                    </form>
                    <script src="https://js.stripe.com/endive/stripe.js"></script>
                @elseif ($buy['state'] === 'confirming')
                    {{-- Paid at Stripe; waiting for the signed webhook, which is the only thing that adds the credits. --}}
                    <div class="mt-4 rounded-xl border-2 border-amber bg-cream-deep p-4" role="status" tabindex="-1" data-flash
                         data-purchase-status-url="{{ route('employer.purchases.status', $buying) }}">
                        <p class="flex items-center gap-2 font-semibold" data-confirming-text>
                            <svg viewBox="0 0 24 24" fill="none" class="size-5 shrink-0 motion-safe:animate-spin" aria-hidden="true" data-confirming-spinner>
                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/>
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                            Confirming with Stripe…
                        </p>
                        <p class="mt-1 text-sm" data-confirming-detail>
                            {{ Money::format($buying->amount_cents) }} in credits for {{ $buying->reference }}. They unlock as soon as Stripe confirms the payment to Local Goods, usually within a few seconds. This updates by itself.
                        </p>
                        <noscript><p class="mt-1 text-sm">Refresh this page to check.</p></noscript>
                        <p hidden class="mt-1 text-sm" data-confirming-timeout>
                            Still waiting for Stripe. {{ $buying->reference }} will show as paid in Purchases once Stripe confirms; refresh this page later to check.
                        </p>
                    </div>
                @else
                    @if ($buy['state'] === 'cancelled')
                        <div class="mt-3 rounded-xl border-2 border-amber bg-cream-deep p-3 text-sm" data-buy-cancelled>
                            <p><span class="font-semibold">Payment cancelled.</span> Nothing was charged for {{ $buying->reference }}. Press Try again to buy the same amount.</p>
                        </div>
                    @endif
                    <p class="mt-1 text-sm text-muted">
                        @if ($stripe)
                            Enter an amount and pay by card right here (Stripe test mode). The credits are ready to give once Stripe confirms the payment.
                        @else
                            Stripe isn't connected on this server, so buying is simulated: the credits are added straight away with no real money.
                        @endif
                    </p>
                    @php
                        $feeHint = PlatformFee::enabled() ? ' A '.PlatformFee::label().' platform fee is added on top.' : '';
                        $prefill = old('purchase_amount', $buy['state'] === 'cancelled' ? Money::toDollars($buying->amount_cents) : null);
                    @endphp
                    <form method="POST" action="{{ route('employer.purchases.store') }}" data-busy="{{ $stripe ? 'Opening the payment form…' : 'Buying…' }}" class="mt-4">
                        @csrf
                        <fieldset @disabled($readOnly) class="space-y-4">
                            <legend class="sr-only">Buy credits</legend>
                            <x-field name="purchase_amount" label="Amount in dollars" :hint="'For example 6000 covers a $150 allowance for 40 people.'.$feeHint">
                                <input id="purchase_amount" name="purchase_amount" type="text" inputmode="decimal" autocomplete="off" required placeholder="6000"
                                       value="{{ $prefill }}"
                                       class="{{ $input }} tabular @error('purchase_amount') border-hold @else border-muted @enderror"
                                       aria-describedby="purchase_amount-hint @error('purchase_amount') purchase_amount-error @enderror" @error('purchase_amount') aria-invalid="true" @enderror>
                            </x-field>
                            @if (PlatformFee::enabled())
                                <p class="tabular text-sm font-semibold" aria-live="polite" data-fee-preview data-fee-basis-points="{{ PlatformFee::basisPoints() }}"></p>
                            @endif
                            <x-button type="submit" variant="accent" :aria-describedby="$readOnly ? 'read-only-note' : null">
                                {{ $buy['state'] === 'cancelled' ? 'Try again' : ($stripe ? 'Buy credits' : 'Buy credits (simulated, no real money)') }}
                            </x-button>
                        </fieldset>
                    </form>
                @endif
            </x-card>

            <x-card as="section" aria-labelledby="purchases-heading" padding="p-0">
                <h3 id="purchases-heading" class="px-5 pt-5 text-xl font-semibold sm:px-6 sm:pt-6">{{ $returned['count'] > 0 ? 'Purchases and returned credit' : 'Purchases' }}</h3>
                @php
                    // Purchases and returned credit in one list, newest first.
                    $activity = $purchases->map(fn ($p) => ['at' => $p->purchased_at, 'purchase' => $p, 'entry' => null])
                        ->concat($returned['entries']->map(fn ($e) => ['at' => $e->created_at, 'purchase' => null, 'entry' => $e]))
                        ->sortByDesc('at')->values();
                @endphp
                @if ($activity->isEmpty())
                    <p class="px-5 pt-2 pb-5 text-muted sm:px-6">No purchases yet. Buy credits to fund your team's credits.</p>
                @else
                    <ul id="credit-activity" class="mt-2 scroll-mt-24 divide-y divide-line/70">
                        @foreach ($activity as $item)
                            @if ($entry = $item['entry'])
                                @php $r = $entry->redemption; @endphp
                                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 sm:px-6" data-returned-entry="{{ $r?->id }}">
                                    <div class="min-w-0">
                                        <p class="tabular font-semibold">+{{ Money::format($entry->amount_cents) }}</p>
                                        <p class="text-sm text-muted">
                                            Denied redemption #{{ $r?->id }} · suspected abuse · decided by {{ $r?->decision?->deciderLabel() ?? 'a BID admin' }}
                                            · {{ $r?->employee?->initials() }} · {{ $entry->created_at->setTimezone('America/New_York')->format('M j, Y') }}
                                        </p>
                                    </div>
                                    <x-badge tone="neutral">Returned credit</x-badge>
                                </li>
                                @continue
                            @endif
                            @php $purchase = $item['purchase']; @endphp
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 sm:px-6">
                                <div class="min-w-0">
                                    <p class="tabular font-semibold">{{ Money::format($purchase->amount_cents) }}</p>
                                    <p class="text-sm text-muted">{{ $purchase->reference }} · {{ $purchase->purchased_at->setTimezone('America/New_York')->format('M j, Y') }}</p>
                                    @if ($purchase->platform_fee_cents > 0)
                                        <p class="tabular text-sm text-muted">+ {{ Money::format($purchase->platform_fee_cents) }} platform fee</p>
                                    @endif
                                </div>
                                @if ($purchase->isCancelled())
                                    <x-badge tone="neutral">Cancelled</x-badge>
                                @elseif ($purchase->isPending())
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-badge tone="hold">Pending</x-badge>
                                        @if ($readOnly)
                                            <span class="text-sm text-muted">Read-only: judges can't pay</span>
                                        @else
                                            <form method="POST" action="{{ route('employer.purchases.pay', $purchase) }}" data-busy="{{ $stripe ? 'Opening the payment form…' : 'Paying…' }}">
                                                @csrf
                                                @if ($stripe)
                                                    <x-button type="submit" size="sm" aria-label="Continue paying for {{ $purchase->reference }}, Stripe test mode">Continue paying</x-button>
                                                @else
                                                    <x-button type="submit" size="sm" aria-label="Pay now for {{ $purchase->reference }}, simulated, no real money">Pay now (simulated, no real money)</x-button>
                                                @endif
                                            </form>
                                        @endif
                                    </div>
                                    @if ($purchase->payment_error)
                                        <p class="basis-full text-sm text-hold"><span aria-hidden="true">⚑</span> Last Stripe attempt: {{ $purchase->payment_error }}</p>
                                    @endif
                                @elseif ($purchase->isStripe())
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-badge tone="ok">Paid</x-badge>
                                        <x-money-mode mode="stripe" :id="$purchase->stripe_payment_intent_id" />
                                    </div>
                                @else
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-badge tone="ok">Paid (simulated)</x-badge>
                                        <x-money-mode mode="simulated" />
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </section>

    {{-- Allocate --}}
    <section id="allocate" aria-labelledby="allocate-heading" class="scroll-mt-24 space-y-4">
        <div>
            <h2 id="allocate-heading" class="text-2xl font-semibold text-forest">Give credits to your team</h2>
            <p class="mt-1 text-muted">Employees are shown by initials and staff number: names stay in your HR system, not here.</p>
        </div>

        @if ($errors->hasAny(['amount', 'employee_id', 'employee_ids', 'occasion', 'recipients']))
            <p id="allocate-error" role="alert" class="rounded-2xl border-2 border-hold bg-hold-bg p-4 font-semibold text-hold">
                <span aria-hidden="true">⚑</span> {{ $errors->first('amount') ?: $errors->first('employee_id') ?: $errors->first('employee_ids') ?: $errors->first('occasion') ?: $errors->first('recipients') }}
            </p>
        @endif

        @if ($employees->isEmpty())
            <x-card class="text-muted">Nobody is enrolled yet. Once employees join, you can give them credits here.</x-card>
        @else
        <div class="grid gap-3 lg:grid-cols-2">
            @foreach (['individual' => 'One person', 'bulk' => 'Several people at once'] as $mode => $title)
                @php
                    // The one error above both forms belongs to the field that caused it, in the form that was sent.
                    $invalid = fn (string ...$fields) => old('mode') === $mode && $errors->hasAny($fields)
                        ? 'aria-invalid="true" aria-describedby="allocate-error"' : '';
                @endphp
                <x-card as="section" aria-labelledby="{{ $mode }}-heading">
                    <h3 id="{{ $mode }}-heading" class="text-xl font-semibold">{{ $title }}</h3>
                    <form method="POST" action="{{ route('employer.allocations.store') }}" data-busy="Giving credits…" class="mt-4">
                        @csrf
                        <input type="hidden" name="mode" value="{{ $mode }}">
                        <fieldset @disabled($readOnly) class="space-y-4">
                            <legend class="sr-only">Give credits to {{ strtolower($title) }}</legend>
                            <x-field name="occasion" id="{{ $mode }}-occasion" label="Occasion" :error="false">
                                <select id="{{ $mode }}-occasion" name="occasion" required data-fill-amount="#{{ $mode }}-amount" class="{{ $input }} border-muted" {!! $invalid('occasion') !!}>
                                    @foreach ($employer->activeOccasions() as $occasion)
                                        <option value="{{ $occasion->value }}" data-amount="{{ Money::toDollars($employer->occasionCents($occasion)) }}"
                                                @selected(old('mode') === $mode ? old('occasion') === $occasion->value : $occasion === Occasion::Monthly)>
                                            {{ $occasion->label() }} ({{ Money::format($employer->occasionCents($occasion)) }})
                                        </option>
                                    @endforeach
                                </select>
                            </x-field>

                            @if ($mode === 'individual')
                                <x-field name="employee_id" id="individual-employee" label="Employee" :error="false">
                                    <select id="individual-employee" name="employee_id" required class="{{ $input }} border-muted" {!! $invalid('employee_id') !!}>
                                        <option value="">Choose an employee</option>
                                        @foreach ($employees as $employee)
                                            <option value="{{ $employee->id }}" @selected((string) old('employee_id') === (string) $employee->id)>
                                                {{ $employee->publicLabel() }} · balance {{ Money::format((int) $employee->balance_cents) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </x-field>
                            @else
                                <fieldset class="space-y-2" @if ($invalid('recipients', 'employee_ids', 'employee_id')) role="radiogroup" @endif {!! $invalid('recipients', 'employee_ids', 'employee_id') !!}>
                                    <legend class="font-semibold">Who</legend>
                                    <label class="flex items-center gap-2">
                                        <input type="radio" name="recipients" value="all" class="size-5 accent-forest" @checked(old('recipients', 'all') === 'all')>
                                        Everyone enrolled ({{ $employees->count() }} people)
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input type="radio" name="recipients" value="selected" class="size-5 accent-forest" @checked(old('recipients') === 'selected')>
                                        Only the people I tick
                                    </label>
                                    <details class="rounded-xl border-2 border-muted/40" @if (old('recipients') === 'selected') open @endif>
                                        <summary class="cursor-pointer px-3 py-2 font-semibold text-forest">Choose people</summary>
                                        {{-- Read-only, the checkboxes can't take focus, so the list itself does, to scroll it by keyboard. --}}
                                        <ul class="grid max-h-56 grid-cols-1 gap-1 overflow-y-auto px-3 pb-3 sm:grid-cols-2" aria-label="Employees" @if ($readOnly) tabindex="0" @endif>
                                            @foreach ($employees as $employee)
                                                <li>
                                                    <label class="flex items-center gap-2 text-sm">
                                                        <input type="checkbox" name="employee_ids[]" value="{{ $employee->id }}" class="size-4 accent-forest"
                                                               @checked(in_array((string) $employee->id, array_map('strval', old('employee_ids', [])), true))>
                                                        <span class="tabular">{{ $employee->publicLabel() }}</span>
                                                    </label>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                </fieldset>
                            @endif

                            <x-field name="amount" id="{{ $mode }}-amount" label="Amount each, in dollars" :error="false">
                                <input id="{{ $mode }}-amount" name="amount" type="text" inputmode="decimal" autocomplete="off" required
                                       value="{{ old('mode') === $mode ? old('amount') : Money::toDollars($employer->monthly_allowance_cents) }}"
                                       class="{{ $input }} tabular border-muted" {!! $invalid('amount') !!}>
                            </x-field>
                            <x-button type="submit" :aria-describedby="$readOnly ? 'read-only-note' : null">{{ $mode === 'individual' ? 'Give credits' : 'Give credits to everyone chosen' }}</x-button>
                        </fieldset>
                    </form>
                </x-card>
            @endforeach
        </div>

        <x-card as="section" aria-labelledby="allocations-heading" padding="p-0">
            <h3 id="allocations-heading" class="px-5 pt-5 text-xl font-semibold sm:px-6 sm:pt-6">Recently given</h3>
            @if ($allocationGroups->isEmpty())
                <p class="px-5 pt-2 pb-5 text-muted sm:px-6">Nothing given yet.</p>
            @else
                <ul class="mt-2 divide-y divide-line/70">
                    @foreach ($allocationGroups as $group)
                        @php
                            $count = $group['allocations']->count();
                            $date = $group['at']->setTimezone('America/New_York')->format('M j, Y');
                        @endphp
                        @if ($count === 1)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 sm:px-6">
                                <div>
                                    <p class="tabular font-semibold">{{ $group['allocations']->first()->employee->publicLabel() }}</p>
                                    <p class="text-sm text-muted">{{ $group['occasion']->label() }} · {{ $date }}</p>
                                </div>
                                <p class="tabular font-semibold">{{ Money::format($group['amount_cents']) }}</p>
                            </li>
                        @else
                            <li>
                                <details class="group">
                                    <summary class="flex cursor-pointer list-none items-start justify-between gap-3 px-5 py-3 hover:bg-cream-deep/50 sm:px-6 [&::-webkit-details-marker]:hidden">
                                        <span class="min-w-0">
                                            <span class="flex items-center gap-1.5 font-semibold">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-4 shrink-0 text-forest transition-transform group-open:rotate-90" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                                                {{ $group['occasion']->shortLabel() }} · {{ $count }} employees
                                            </span>
                                            <span class="block pl-5.5 text-sm text-muted">{{ Money::format($group['amount_cents']) }} each · {{ $date }}</span>
                                        </span>
                                        <span class="tabular shrink-0 font-semibold">{{ Money::format($group['amount_cents'] * $count) }}</span>
                                    </summary>
                                    <ul class="grid grid-cols-2 gap-x-4 gap-y-1 bg-cream-deep/40 px-5 py-3 text-sm sm:grid-cols-3 sm:px-6" aria-label="{{ $count }} employees in this allocation">
                                        @foreach ($group['allocations'] as $allocation)
                                            <li class="tabular">{{ $allocation->employee->publicLabel() }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            </li>
                        @endif
                    @endforeach
                </ul>
            @endif
        </x-card>
        @endif
    </section>

    {{-- Program setup --}}
    <section id="program" aria-labelledby="program-heading" class="scroll-mt-24">
        <x-card>
            <h2 id="program-heading" class="text-2xl font-semibold text-forest">Program setup</h2>
            <p class="mt-1 text-muted">Your monthly budget caps how much can be given out each calendar month, across allowances and occasions.</p>
            <form method="POST" action="{{ route('employer.program.update') }}" data-busy="Saving…" class="mt-5">
                @csrf
                <fieldset @disabled($readOnly) class="space-y-6">
                    <legend class="sr-only">Program setup</legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field name="monthly_budget" label="Monthly budget, in dollars" hint="Leave empty for no cap.">
                            <input id="monthly_budget" name="monthly_budget" type="text" inputmode="decimal" autocomplete="off"
                                   value="{{ old('monthly_budget', $employer->monthly_budget_cents === null ? '' : Money::toDollars($employer->monthly_budget_cents)) }}"
                                   class="{{ $input }} tabular @error('monthly_budget') border-hold @else border-muted @enderror"
                                   aria-describedby="monthly_budget-hint @error('monthly_budget') monthly_budget-error @enderror" @error('monthly_budget') aria-invalid="true" @enderror>
                        </x-field>
                        <x-field name="monthly_allowance" label="Allowance per employee each month, in dollars">
                            <input id="monthly_allowance" name="monthly_allowance" type="text" inputmode="decimal" autocomplete="off" required
                                   value="{{ old('monthly_allowance', Money::toDollars($employer->monthly_allowance_cents)) }}"
                                   class="{{ $input }} tabular @error('monthly_allowance') border-hold @else border-muted @enderror"
                                   @error('monthly_allowance') aria-invalid="true" aria-describedby="monthly_allowance-error" @enderror>
                        </x-field>
                    </div>

                    <fieldset>
                        <legend class="font-semibold">Occasions (optional)</legend>
                        <p class="text-sm text-muted">Switch one on to give that amount from "Give credits to your team".</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                            @foreach (Occasion::optional() as $occasion)
                                @php
                                    $key = "occasions.{$occasion->value}";
                                    $cents = $employer->occasionCents($occasion);
                                    $fieldId = "occasion-{$occasion->value}-amount";
                                @endphp
                                <div class="rounded-xl border-2 border-muted/40 p-3">
                                    <label class="flex items-center gap-2 font-semibold">
                                        <input type="hidden" name="occasions[{{ $occasion->value }}][enabled]" value="0">
                                        <input type="checkbox" name="occasions[{{ $occasion->value }}][enabled]" value="1" class="size-5 accent-forest"
                                               @checked(old("{$key}.enabled", $cents !== null ? '1' : '0') === '1')>
                                        {{ $occasion->label() }}
                                    </label>
                                    <x-field :name="$key.'.amount'" :id="$fieldId" label="Amount, in dollars" class="mt-2">
                                        <input id="{{ $fieldId }}" name="occasions[{{ $occasion->value }}][amount]" type="text" inputmode="decimal" autocomplete="off"
                                               value="{{ old("{$key}.amount", $cents === null ? '' : Money::toDollars($cents)) }}"
                                               class="{{ $input }} tabular @error($key.'.amount') border-hold @else border-muted @enderror"
                                               @error($key.'.amount') aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror>
                                    </x-field>
                                </div>
                            @endforeach
                        </div>
                    </fieldset>

                    <x-button type="submit" :aria-describedby="$readOnly ? 'read-only-note' : null">Save program</x-button>
                </fieldset>
            </form>
        </x-card>
    </section>
</div>
@endif
</x-app-shell>
