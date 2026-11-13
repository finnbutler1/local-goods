@php
    use App\Enums\Occasion;
    use App\Services\Payments\StripeCheckout;
    use App\Support\EmployerPlatformFee;
    use App\Support\Money;
    use App\Support\PlatformFee;

    $input = 'block w-full rounded-xl border-2 bg-surface px-3 py-3 focus-visible:border-primary';
    $readOnly = Gate::denies('manage-employer-program');
    $link = 'font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary';
@endphp

<x-app-shell title="Employer dashboard" active="dashboard"
             :context="($employer?->name ?? 'Employer').' · Golden Triangle BID'">
@if ($employer === null)
    <section aria-labelledby="empty-heading" class="ledger-note border-dashed px-6 py-12">
        <h1 id="empty-heading" class="text-2xl font-extrabold tracking-tight">No employer program is set up yet</h1>
        <p class="mt-2 max-w-md text-muted">Once your company joins Local Goods, your budget, credits and your team's neighborhood visits appear here.</p>
    </section>
@else
<div class="admin-desk space-y-14">
    <header>
        <x-station-sign as="p">Employer dashboard · {{ $employer->name }}</x-station-sign>
        <h1 class="page-title">Your team got out into the neighborhood.</h1>
        <p class="page-lede">
            {{ $metrics['month']->format('F') }} so far: who used their perk, where they went, and how much stayed with independent Golden Triangle businesses.
            Every number is worked out from the credit ledger, and each one says how.
        </p>
    </header>

    @if ($readOnly)
        <p id="read-only-note" class="ledger-note text-sm">
            <span class="font-semibold">Read-only view.</span> Judges can look at every form here but can't buy, pay or give credits.
        </p>
    @endif

    {{-- Engagement --}}
    <section aria-labelledby="engagement-heading" class="space-y-10">
        <h2 id="engagement-heading" class="stop-subtitle text-2xl">This month in the neighborhood</h2>

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
                    'detail' => ($metrics['refunded_cents'] > 0 ? 'Net of refunds · ' : '').($metrics['held_count'] > 0 ? Money::format($metrics['held_cents']).' more waiting for review' : 'at independent merchants'),
                    'how' => 'The total of cleared redemptions this month, less refunds that completed this month. Redemptions held by Redemption Fraud Detection are left out until a person at the BID clears them.',
                ],
            ];
        @endphp
        <dl class="grid gap-x-6 gap-y-10 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($tiles as $tile)
                <div class="rule-heavy flex flex-col">
                    <dt class="figure-label">{{ $tile['label'] }}</dt>
                    <dd class="mt-2">
                        <span class="money text-5xl">{{ $tile['value'] }}</span>
                        @isset($tile['unit'])<span class="font-semibold"> {{ $tile['unit'] }}</span>@endisset
                    </dd>
                    <dd class="mt-1.5 text-sm text-muted">{{ $tile['detail'] }}</dd>
                    <dd class="mt-3 border-t border-line pt-2 text-sm">
                        <details>
                            <summary class="cursor-pointer font-semibold text-primary">How we count this</summary>
                            <p class="mt-1 text-muted">{{ $tile['how'] }}</p>
                        </details>
                    </dd>
                </div>
            @endforeach
        </dl>

        <div class="grid gap-x-6 gap-y-12 lg:grid-cols-3">
            <section aria-labelledby="trend-heading" class="rule-top lg:col-span-2">
                @php
                    $weeks = $metrics['weeks'];
                    $max = max(1, max(array_column($weeks, 'visits')));
                @endphp
                <h3 id="trend-heading" class="text-xl font-bold tracking-tight">Visits per week, last {{ count($weeks) }} weeks</h3>
                <p class="mt-1 text-sm text-muted">Monday to Sunday. The last bar is this week so far.</p>

                @if (array_sum(array_column($weeks, 'visits')) === 0)
                    <p class="ledger-note mt-6 border-dashed text-muted">No visits yet. Once your team redeems credits, each week's visits show here.</p>
                @else
                    {{-- Bars are hidden from screen readers: the sentence under them sums them up and
                         "Show as a table" has every week's numbers. Hover or tap a bar for its week. --}}
                    <div class="mt-6 flex h-44 items-end gap-1.5 border-b border-muted/40 sm:gap-3" aria-hidden="true">
                        @foreach ($weeks as $week)
                            <div class="group relative flex h-full flex-1 items-end justify-center" data-bar>
                                <div class="w-full max-w-12 rounded-t-[3px] bg-primary transition-colors group-hover:bg-primary-hover group-data-open:bg-primary-hover"
                                     style="height: {{ max(2, round(100 * $week['visits'] / $max)) }}%"></div>
                                <div @class(['pointer-events-none absolute bottom-full z-10 mb-2 hidden w-max rounded-lg bg-night px-2.5 py-1.5 text-xs text-on-night shadow group-hover:block group-data-open:block', 'left-0' => $loop->first, 'right-0' => $loop->last, 'left-1/2 -translate-x-1/2' => ! $loop->first && ! $loop->last])>
                                    <span class="font-semibold">Week of {{ $week['start']->format('M j') }}</span><br>
                                    {{ $week['visits'] }} {{ Str::plural('visit', $week['visits']) }} · {{ Money::format($week['cents']) }}{{ $week['refunded_cents'] > 0 ? ' net of refunds' : '' }}
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
                    <summary class="cursor-pointer font-semibold text-primary">Show as a table</summary>
                    <table data-rtable="fit" class="mt-2 w-full text-left">
                        <caption class="sr-only">Cleared visits and dollars spent locally per week</caption>
                        <thead><tr><th scope="col" class="py-1.5">Week of</th><th scope="col" class="py-1.5 text-right">Visits</th><th scope="col" class="py-1.5 text-right">Spent locally</th></tr></thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($weeks as $week)
                                <tr><th scope="row" class="py-1.5 font-normal">{{ $week['start']->format('M j') }}</th><td class="tabular py-1.5 text-right">{{ $week['visits'] }}</td><td class="tabular py-1.5 text-right">{{ Money::format($week['cents']) }}@if ($week['refunded_cents'] > 0) <span class="text-muted">(net of {{ Money::format($week['refunded_cents']) }} refunded)</span>@endif</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="mt-2 text-muted">How we count this: cleared redemptions by the week they happened, in Washington, DC time, less refunds in the week they completed.</p>
                </details>
            </section>

            <section aria-labelledby="categories-heading" class="rule-top">
                <h3 id="categories-heading" class="text-xl font-bold tracking-tight">Top categories</h3>
                <p class="mt-1 text-sm text-muted">By dollars spent locally this month.</p>
                @if ($metrics['categories'] === [])
                    <p class="ledger-note mt-4 border-dashed text-muted">Nothing spent yet this month.</p>
                @else
                    @php $top = max(1, $metrics['categories'][0]['cents']); @endphp
                    <ol class="mt-4 space-y-3">
                        @foreach (array_slice($metrics['categories'], 0, 5) as $category)
                            <li>
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="font-semibold capitalize">{{ $category['category'] }}</span>
                                    <span class="tabular text-sm">{{ Money::format($category['cents']) }}{{ ($metrics['refunded_by_category'][$category['category']] ?? 0) > 0 ? ' net of refunds' : '' }} · {{ $category['visits'] }} {{ Str::plural('visit', $category['visits']) }}</span>
                                </div>
                                <div class="mt-1.5 h-2 rounded-[2px] bg-band" aria-hidden="true">
                                    <div class="h-2 rounded-[2px] bg-primary" style="width: {{ max(2, round(100 * $category['cents'] / $top)) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
                <details class="mt-4 text-sm">
                    <summary class="cursor-pointer font-semibold text-primary">How we count this</summary>
                    <p class="mt-1 text-muted">Cleared redemptions this month, grouped by the merchant's category and added up, less refunds that completed this month.</p>
                </details>
            </section>
        </div>
    </section>

    {{-- Credits --}}
    <section id="credits" aria-labelledby="credits-heading" class="scroll-mt-24 space-y-8">
        <div>
        <h2 id="credits-heading" class="stop-subtitle text-2xl">Credits</h2>
        {{-- How credits are taxed for employees (ADR 0027): one honest line, no numbers, not tax advice. --}}
        <p class="mt-1.5 text-sm text-muted" data-tax-note>Credits that work like cash are generally taxable wages for employees. Confirm the treatment with your tax adviser.</p>
        </div>

        <dl class="grid gap-x-6 gap-y-10 sm:grid-cols-3">
            <div class="rule-heavy">
                <dt class="figure-label">Ready to give</dt>
                <dd class="money mt-2 text-5xl">{{ Money::format($balance) }}</dd>
                <dd class="mt-1.5 text-sm text-muted">Paid credits not yet given to employees</dd>
                @if ($returned['count'] > 0)
                    <dd class="mt-3 border-t border-line pt-2 text-sm" data-returned-credit="{{ $returned['cents'] }}">
                        @if ($returned['cents'] <= $balance)
                            Includes {{ Money::format($returned['cents']) }} returned from {{ $returned['count'] }} denied {{ Str::plural('redemption', $returned['count']) }}.
                        @else
                            {{ Money::format($returned['cents']) }} came back from {{ $returned['count'] }} denied {{ Str::plural('redemption', $returned['count']) }}; some of it has been given out again.
                        @endif
                        <a href="#credit-activity" class="font-semibold text-primary underline underline-offset-4">See returned credit</a>
                    </dd>
                @endif
            </div>
            <div class="rule-heavy">
                <dt class="figure-label">Pending payment</dt>
                <dd class="money mt-2 text-5xl">{{ Money::format($pendingCents) }}</dd>
                <dd class="mt-1.5 text-sm text-muted">Can't be given out until paid</dd>
            </div>
            <div class="rule-heavy">
                <dt class="figure-label">Given out this month</dt>
                <dd class="money mt-2 text-5xl">{{ Money::format($allocatedThisMonth) }}</dd>
                <dd class="mt-1.5 text-sm text-muted">
                    @if ($remainingBudget === null)
                        No monthly budget set
                    @else
                        {{ Money::format($remainingBudget) }} left of the {{ Money::format($employer->monthly_budget_cents) }} budget
                    @endif
                </dd>
            </div>
        </dl>

        <div class="grid gap-x-6 gap-y-12 xl:grid-cols-12">
            @php
                $buying = $buy['purchase'];
                // Only when a fee is on (ADR 0021, ADR 0042); with both off nothing here mentions fees, apart from the planned-fee preview.
                $feeLine = fn ($p) => match (true) {
                    $p->hasEmployerFee() => 'Credits '.Money::format($p->amount_cents).' + platform fee '.Money::format($p->employer_fee_cents)
                        .' ('.StripeCheckout::employerFeeLine($p).') = total '.Money::format($p->totalCents()),
                    $p->platform_fee_cents > 0 => 'Credits '.Money::format($p->amount_cents).' + platform fee '.Money::format($p->platform_fee_cents).' = total '.Money::format($p->totalCents()),
                    default => null,
                };
                $feeNotRefundable = 'The platform fee is Local Goods\' own revenue, not credits, and can\'t be refunded in this version.';
            @endphp
            <x-card as="section" id="buy" aria-labelledby="buy-heading" @class(['scroll-mt-24 min-w-0 self-start', 'xl:col-span-12' => $buy['state'] === 'paying', 'xl:col-span-5' => $buy['state'] !== 'paying'])>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 id="buy-heading" class="text-xl font-bold tracking-tight">Buy credits</h3>
                    <x-money-mode />
                </div>

                @if ($buy['state'] === 'paying')
                    {{-- Stripe's embedded Checkout (resources/js/checkout.js). The credits unlock only when Stripe's webhook confirms. --}}
                    <p class="mt-2">
                        Paying for <span class="font-semibold">{{ $buying->reference }}</span>:
                        <span class="tabular font-semibold">{{ Money::format($buying->amount_cents) }}</span> in credits.
                    </p>
                    @if ($line = $feeLine($buying))
                        <p class="tabular mt-1 font-semibold" data-fee-breakdown @if ($buying->hasEmployerFee()) data-employer-fee-live @endif>{{ $line }}@if ($buying->hasEmployerFee()) <span class="font-normal text-muted">({{ EmployerPlatformFee::moneyLabel($buying) }})</span>@endif</p>
                    @endif
                    @if ($buying->hasEmployerFee())
                        <p class="mt-1 text-sm" data-employer-fee-refunds>{{ $feeNotRefundable }}</p>
                    @endif
                    <p id="test-card-hint" class="mt-3 rounded-xl border-2 border-accent bg-band p-3 text-sm">
                        <strong>Test mode:</strong> use card <span class="tabular font-mono">4000 0000 0000 0077</span>, any future date, any CVC.
                        No real money moves. Stripe holds the money, not Local Goods.
                    </p>
                    @include('partials.customer-funds-note', ['stripe' => true])
                    <div class="mt-4 w-full min-w-0 overflow-hidden rounded-xl" data-embedded-checkout aria-label="Stripe payment form, test mode"
                         role="region" aria-describedby="test-card-hint"
                         data-stripe-key="{{ config('services.stripe.key') }}"
                         data-secret-url="{{ route('employer.purchases.checkout', $buying) }}"
                         data-csrf="{{ csrf_token() }}">
                        <p class="flex items-center gap-2 p-4 text-muted" data-checkout-loading><span class="spinner" aria-hidden="true"></span>Loading Stripe's payment form…</p>
                    </div>
                    <noscript>
                        <p class="mt-3 rounded-xl bg-band p-3 text-sm">Paying by card needs JavaScript, because the card form is Stripe's. Turn it on and reload this page.</p>
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
                    <div class="mt-4 rounded-xl border-2 border-accent bg-band p-4" role="status" tabindex="-1" data-flash
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
                    @include('partials.customer-funds-note', ['stripe' => true])
                @else
                    @if ($buy['state'] === 'cancelled')
                        <div class="mt-3 rounded-xl border-2 border-accent bg-band p-3 text-sm" data-buy-cancelled>
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
                    @include('partials.customer-funds-note', ['stripe' => $stripe])
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
                            @if ($employerFee['on'])
                                {{-- The employer platform fee (ADR 0042): added once a month, on the first purchase. --}}
                                <p class="text-sm" data-employer-fee-next="{{ $employerFee['adds'] ? 'adds' : 'none' }}" data-employer-fee-live>
                                    <span class="block font-semibold text-muted">{{ EmployerPlatformFee::formLabel() }}.</span>
                                    @if ($employerFee['adds'])
                                        <span class="tabular font-semibold">This purchase adds the platform fee for {{ EmployerPlatformFee::monthLabel($employerFee['month']) }}:
                                            {{ $employerFee['employees'] }} participating {{ Str::plural('employee', $employerFee['employees']) }} × {{ Money::format($employerFee['unit_cents']) }} = {{ Money::format($employerFee['cents']) }}.</span>
                                        Participating means they received credits last month. It's charged once a month, on the first purchase. {{ $feeNotRefundable }}
                                    @else
                                        No platform fee on this purchase: it's charged once a month, on the first purchase, for the employees who received credits last month.
                                    @endif
                                </p>
                            @endif
                            <x-button type="submit" variant="accent" class="max-sm:w-full" :aria-describedby="$readOnly ? 'read-only-note' : null">
                                {{ $buy['state'] === 'cancelled' ? 'Try again' : ($stripe ? 'Buy credits' : 'Buy credits (simulated, no real money)') }}
                            </x-button>
                        </fieldset>
                    </form>
                    @unless ($employerFee['on'])
                        {{-- Display only (ADR 0042): the planned fee is built and tested but switched off during the pilot; nothing is charged. --}}
                        @php $mayOpenModel = auth()->user()?->hasRole(\App\Enums\Role::Judge, \App\Enums\Role::BidAdmin, \App\Enums\Role::PlatformAdmin); @endphp
                        <aside class="mt-6 rounded-xl border-2 border-line bg-band p-4 text-sm" aria-labelledby="planned-fee-heading" data-planned-fee-preview>
                            <p class="font-semibold text-muted">{{ config('projections.label') }}</p>
                            <h4 id="planned-fee-heading" class="mt-1 text-base font-bold">Planned platform fee (not charged during the pilot)</h4>
                            <p class="mt-1">
                                The plan is <span class="tabular font-semibold">{{ Money::format($employerFee['unit_cents']) }}</span> per participating employee per month,
                                added once a month to the first credit purchase. Participating means they received credits the month before.
                                @if ($employerFee['employees'] > 0)
                                    Last month {{ $employerFee['employees'] }} {{ Str::plural('employee', $employerFee['employees']) }} received credits here, so it would come to
                                    <span class="tabular font-semibold">{{ Money::format($employerFee['cents']) }}</span>.
                                @endif
                                Nothing is charged now.
                            </p>
                            <p class="mt-1">
                                The figure comes from the
                                @if ($mayOpenModel)
                                    <a href="{{ route('business-model') }}#assumptions" class="{{ $link }}">business model's stated assumptions</a>.
                                @else
                                    business model's stated assumptions.
                                @endif
                            </p>
                        </aside>
                    @endunless
                @endif
            </x-card>

            <section aria-labelledby="purchases-heading" class="rule-top min-w-0 xl:col-span-7">
                <h3 id="purchases-heading" class="text-xl font-bold tracking-tight">{{ $returned['count'] > 0 ? 'Purchases and returned credit' : 'Purchases' }}</h3>
                @php
                    // Purchases and returned credit in one list, newest first.
                    $activity = $purchases->map(fn ($p) => ['at' => $p->purchased_at, 'purchase' => $p, 'entry' => null])
                        ->concat($returned['entries']->map(fn ($e) => ['at' => $e->created_at, 'purchase' => null, 'entry' => $e]))
                        ->sortByDesc('at')->values();
                    if (! $showAllPurchases) {
                        $activity = $activity->take(\App\Support\ShowMore::CAP);
                    }
                @endphp
                @if ($activity->isEmpty())
                    <p class="ledger-note mt-4 border-dashed text-muted">No purchases yet. Buy credits to fund your team's credits.</p>
                @else
                    <x-rtable :label="$returned['count'] > 0 ? 'Purchases and returned credit' : 'Purchases'" id="credit-activity" class="mt-4 scroll-mt-24">
                        <table data-rtable="stack" class="w-full text-left text-sm">
                            <caption class="sr-only">{{ $returned['count'] > 0 ? 'Purchases and returned credit' : 'Purchases' }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="py-2 pr-4 text-right">Amount</th>
                                    <th scope="col" class="py-2 pr-4">Details</th>
                                    <th scope="col" class="py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody id="credit-activity-rows">
                                @foreach ($activity as $item)
                                    @php $folded = $loop->index >= \App\Support\ShowMore::PURCHASES && ! ($item['purchase']?->isPending()); @endphp
                                    @if ($entry = $item['entry'])
                                        @php $r = $entry->redemption; @endphp
                                        <tr class="border-b border-line align-top" data-returned-entry="{{ $r?->id }}" @if ($folded) data-more @endif>
                                            <th scope="row" data-primary class="tabular py-3 pr-4 text-right text-base font-semibold whitespace-nowrap">+{{ Money::format($entry->amount_cents) }}</th>
                                            <td data-label="Details" class="stack-block py-3 pr-4 text-muted">
                                                Denied redemption #{{ $r?->id }}{{ ($for = $r?->itemsSummary()) ? ' ('.$for.')' : '' }} · suspected abuse · decided by {{ $r?->decision?->deciderLabel() ?? 'a BID admin' }}
                                                · {{ $r?->employee?->initials() }} · {{ $entry->created_at->setTimezone('America/New_York')->format('M j, Y') }}
                                            </td>
                                            <td data-label="Status" class="stack-block py-3"><span class="flex flex-wrap gap-2">@if ($r?->isGeneratedDemoActivity()) <x-demo-activity-badge /> @endif<x-badge tone="neutral">Returned credit</x-badge></span></td>
                                        </tr>
                                        @continue
                                    @endif
                                    @php $purchase = $item['purchase']; @endphp
                                    <tr class="border-b border-line align-top" @if ($folded) data-more @endif>
                                        <th scope="row" data-primary class="tabular py-3 pr-4 text-right text-base font-semibold whitespace-nowrap">{{ Money::format($purchase->amount_cents) }} @if ($purchase->isGeneratedDemoActivity()) <x-demo-activity-badge /> @endif</th>
                                        <td data-label="Details" class="stack-block py-3 pr-4">
                                            <p class="text-muted">{{ $purchase->reference }} · {{ $purchase->purchased_at->setTimezone('America/New_York')->format('M j, Y') }}</p>
                                            @if ($purchase->platform_fee_cents > 0)
                                                <p class="ledger-num text-muted">+ {{ Money::format($purchase->platform_fee_cents) }} platform fee</p>
                                            @endif
                                            @if ($purchase->hasEmployerFee())
                                                <p class="ledger-num text-muted" data-employer-fee-row data-employer-fee-live>+ {{ Money::format($purchase->employer_fee_cents) }} {{ StripeCheckout::employerFeeLine($purchase) }} · not refundable · {{ EmployerPlatformFee::moneyLabel($purchase) }}</p>
                                            @endif
                                        </td>
                                        <td data-label="Status" class="stack-block py-3">
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
                                                    <p class="mt-1 text-sm text-hold"><span aria-hidden="true">⚑</span> Last Stripe attempt: {{ $purchase->payment_error }}</p>
                                                @endif
                                            @elseif ($purchase->isStripe())
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <x-badge tone="ok">Paid</x-badge>
                                                    <x-money-mode mode="stripe" :id="$purchase->stripe_payment_intent_id" />
                                                </div>
                                                <p class="mt-1 text-sm text-muted" data-held-in="customer-funds">Held in the customer funds account until your staff spend it.</p>
                                            @else
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <x-badge tone="ok">Paid (simulated)</x-badge>
                                                    <x-money-mode mode="simulated" />
                                                </div>
                                                <p class="mt-1 text-sm text-muted" data-held-in="customer-funds">Recorded as customer funds until your staff spend it (simulated).</p>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-rtable>
                    <x-show-more target="credit-activity-rows" :limit="\App\Support\ShowMore::PURCHASES" :loaded="$activity->count()" noun="purchases"
                        :total="$showAllPurchases ? null : $purchasesCount + $returned['count']" :reload="route('employer.dashboard', ['all_purchases' => 1]).'#credit-activity'" />
                @endif
            </section>
        </div>

        {{-- Refund of unspent credits (ADR 0028): a request only; a platform admin decides. --}}
        <section id="refund-credits" aria-labelledby="refund-credits-heading" class="rule-top scroll-mt-24">
            <h3 id="refund-credits-heading" class="text-xl font-bold tracking-tight">Refund unspent credits</h3>
            <p class="mt-1.5 max-w-3xl text-sm text-muted">
                Credits you haven't given to employees ({{ Money::format($balance) }} now) can go back to the card that paid for them,
                newest payment first, as a Stripe test-mode refund. Simulated purchases are returned as simulated, with no real money.
                A Local Goods admin decides and tells you why.
            </p>
            @include('partials.correction-request', [
                'for' => 'employer:'.$employer->id, 'context' => \App\Support\CorrectionReasons::EMPLOYER_CREDITS,
                'correction' => $refundCorrection, 'canAsk' => $balance > 0,
                'summary' => 'Refund unspent credits', 'action' => route('employer.correction.store'),
            ])
        </section>
    </section>

    {{-- Allocate --}}
    <section id="allocate" aria-labelledby="allocate-heading" class="scroll-mt-24 space-y-8">
        <div>
            <h2 id="allocate-heading" class="stop-subtitle text-2xl">Give credits to your team</h2>
            <p class="mt-1.5 text-muted">Employees are shown by initials and staff number: names stay in your HR system, not here.</p>
        </div>

        @if ($errors->hasAny(['amount', 'employee_id', 'employee_ids', 'occasion', 'recipients']))
            <p id="allocate-error" role="alert" class="border-t-[3px] border-hold bg-hold-bg p-4 font-semibold text-hold">
                <span aria-hidden="true">⚑</span> {{ $errors->first('amount') ?: $errors->first('employee_id') ?: $errors->first('employee_ids') ?: $errors->first('occasion') ?: $errors->first('recipients') }}
            </p>
        @endif

        @if ($employees->isEmpty())
            <p class="ledger-note border-dashed text-muted">Nobody is enrolled yet. Once employees join, you can give them credits here.</p>
        @else
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach (['individual' => 'One person', 'bulk' => 'Several people at once'] as $mode => $title)
                @php
                    // The one error above both forms belongs to the field that caused it, in the form that was sent.
                    $invalid = fn (string ...$fields) => old('mode') === $mode && $errors->hasAny($fields)
                        ? 'aria-invalid="true" aria-describedby="allocate-error"' : '';
                @endphp
                <x-card as="section" aria-labelledby="{{ $mode }}-heading">
                    <h3 id="{{ $mode }}-heading" class="text-xl font-bold tracking-tight">{{ $title }}</h3>
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
                                        <input type="radio" name="recipients" value="all" class="size-5 accent-primary" @checked(old('recipients', 'all') === 'all')>
                                        Everyone enrolled ({{ $employees->count() }} people)
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input type="radio" name="recipients" value="selected" class="size-5 accent-primary" @checked(old('recipients') === 'selected')>
                                        Only the people I tick
                                    </label>
                                    <details class="rounded-xl border-2 border-edge" @if (old('recipients') === 'selected') open @endif>
                                        <summary class="cursor-pointer px-3 py-2 font-semibold text-primary">Choose people</summary>
                                        {{-- Read-only, the checkboxes can't take focus, so the list itself does, to scroll it by keyboard. --}}
                                        <ul class="grid max-h-56 grid-cols-1 gap-1 overflow-y-auto px-3 pb-3 sm:grid-cols-2" aria-label="Employees" @if ($readOnly) tabindex="0" @endif>
                                            @foreach ($employees as $employee)
                                                <li>
                                                    <label class="flex items-center gap-2 text-sm">
                                                        <input type="checkbox" name="employee_ids[]" value="{{ $employee->id }}" class="size-4 accent-primary"
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
                            <x-button type="submit" class="max-sm:w-full" :aria-describedby="$readOnly ? 'read-only-note' : null">{{ $mode === 'individual' ? 'Give credits' : 'Give credits to everyone chosen' }}</x-button>
                        </fieldset>
                    </form>
                </x-card>
            @endforeach
        </div>

        <section aria-labelledby="allocations-heading">
            <h3 id="allocations-heading" class="text-xl font-bold tracking-tight">Recently given</h3>
            @if ($allocationGroups->isEmpty())
                <p class="ledger-note mt-3 border-dashed text-muted">Nothing given yet.</p>
            @else
                <ul id="allocation-rows" class="mt-3 divide-y divide-line border-t-[3px] border-b border-t-ink border-b-line">
                    @foreach ($allocationGroups as $group)
                        @php
                            $count = $group['allocations']->count();
                            $date = $group['at']->setTimezone('America/New_York')->format('M j, Y');
                        @endphp
                        @if ($count === 1)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-3" @if ($loop->index >= \App\Support\ShowMore::GIVEN) data-more @endif>
                                <div>
                                    <p class="tabular font-semibold">{{ $group['allocations']->first()->employee->publicLabel() }}</p>
                                    <p class="text-sm text-muted">{{ $group['occasion']->label() }} · {{ $date }}</p>
                                </div>
                                <p class="ledger-num font-semibold">{{ Money::format($group['amount_cents']) }}</p>
                            </li>
                        @else
                            <li @if ($loop->index >= \App\Support\ShowMore::GIVEN) data-more @endif>
                                <details class="group">
                                    <summary class="flex cursor-pointer list-none items-start justify-between gap-3 py-3 hover:bg-band/50 [&::-webkit-details-marker]:hidden">
                                        <span class="min-w-0">
                                            <span class="flex items-center gap-1.5 font-semibold">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-4 shrink-0 text-primary transition-transform group-open:rotate-90" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                                                {{ $group['occasion']->shortLabel() }} · {{ $count }} employees
                                            </span>
                                            <span class="block pl-5.5 text-sm text-muted">{{ Money::format($group['amount_cents']) }} each · {{ $date }}</span>
                                        </span>
                                        <span class="ledger-num shrink-0 font-semibold">{{ Money::format($group['amount_cents'] * $count) }}</span>
                                    </summary>
                                    <ul class="grid grid-cols-2 gap-x-4 gap-y-1 bg-band/60 px-4 py-3 text-sm sm:grid-cols-3" aria-label="{{ $count }} employees in this allocation">
                                        @foreach ($group['allocations'] as $allocation)
                                            <li class="tabular">{{ $allocation->employee->publicLabel() }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            </li>
                        @endif
                    @endforeach
                </ul>
                <x-show-more target="allocation-rows" :limit="\App\Support\ShowMore::GIVEN" :loaded="$allocationGroups->count()" noun="allocations"
                    :total="$showAllGiven ? null : $allocationGroupsTotal" :reload="route('employer.dashboard', ['all_given' => 1]).'#allocations-heading'" />
            @endif
        </section>
        @endif
    </section>

    {{-- Program setup --}}
    <section id="program" aria-labelledby="program-heading" class="scroll-mt-24">
        <x-card>
            <h2 id="program-heading" class="stop-subtitle text-2xl">Program setup</h2>
            <p class="mt-1.5 text-muted">Your monthly budget caps how much can be given out each calendar month, across allowances and occasions.</p>
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
                                <div class="rounded-xl border-2 border-line p-3">
                                    <label class="flex items-center gap-2 font-semibold">
                                        <input type="hidden" name="occasions[{{ $occasion->value }}][enabled]" value="0">
                                        <input type="checkbox" name="occasions[{{ $occasion->value }}][enabled]" value="1" class="size-5 accent-primary"
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

                    <x-button type="submit" class="max-sm:w-full" :aria-describedby="$readOnly ? 'read-only-note' : null">Save program</x-button>
                </fieldset>
            </form>
        </x-card>
    </section>
</div>
@endif
</x-app-shell>
