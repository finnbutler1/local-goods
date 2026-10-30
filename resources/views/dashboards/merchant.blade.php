@php use App\Support\Money; use App\Support\SalesTax; $figures = \App\Support\MerchantFee::showsFiguresTo(auth()->user()); @endphp

<x-app-shell title="Merchant dashboard" active="dashboard"
             :context="($merchant?->name ?? 'Merchant').' · Golden Triangle BID'">
@if ($merchant === null)
    <x-card as="section" aria-labelledby="empty-heading" class="border-2 border-dashed border-line ring-0! text-center" padding="px-6 py-12">
        <h1 id="empty-heading" class="text-2xl font-semibold text-primary">No business is linked to this login yet</h1>
        <p class="mx-auto mt-2 max-w-md text-muted">Once the BID adds your business to Local Goods, your sales and payouts appear here. Ask the BID team if you expected to see them.</p>
    </x-card>
@else
<div class="space-y-10">
    @if ($joining)
        <div class="max-w-3xl space-y-3" data-joining>
            @include('apply.partials.prototype-notice')
            <p class="rounded-2xl bg-band p-4">
                <span class="font-semibold">Not live yet.</span> The BID approved {{ $merchant->name }}. Employees can spend credits here
                once Stripe confirms your payouts account below.
            </p>
        </div>
    @endif
    <header class="max-w-3xl">
        <x-eyebrow class="text-accent-ink">Merchant dashboard · {{ $merchant->name }}</x-eyebrow>
        <h1 class="mt-1 text-title font-semibold text-primary">Your Local Goods sales</h1>
        <p class="mt-3 text-lg text-muted">
            Customers from employers in the BID pay with Local Goods credits. You're owed a redemption once it clears.
            @if ($stripe)
                Redemptions paid for with Stripe-paid credits are sent to your Stripe account (test mode) as they clear; older, simulated history stays simulated.
            @else
                Stripe isn't connected on this server, so payouts are simulated: recorded in the ledger, no money moves.
            @endif
        </p>
        <p class="mt-3"><a href="{{ route('merchant.menu') }}" class="font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">See and change your menu</a> ({{ $merchant->products()->count() }} {{ Str::plural('product', $merchant->products()->count()) }})</p>
        <x-money-mode class="mt-3" />
    </header>

    {{-- Pay in person (ADR 0026): the customer shows a pay code, the cashier types it here and confirms. --}}
    <x-card as="section" id="take-payment" aria-labelledby="take-payment-heading" class="scroll-mt-24 max-w-xl border-2 border-primary ring-0!">
        <h2 id="take-payment-heading" class="text-2xl font-semibold text-primary">Take a payment</h2>
        @if ($payResult)
            @php $paidOk = $payResult->isApproved(); @endphp
            <div @class(['mt-4 rounded-2xl border-2 p-4', 'border-ok bg-ok-bg text-ok' => $paidOk, 'border-hold bg-hold-bg text-hold' => ! $paidOk]) data-pay-result>
                <p class="font-display text-2xl font-semibold">{{ $paidOk ? 'Payment confirmed' : 'Payment not completed yet' }}</p>
                <p class="tabular mt-1 text-lg font-semibold text-ink">{{ Money::format($payResult->amount_cents) }} · {{ $payResult->employee->initials() }}</p>
                @if ($for = $payResult->itemsSummary()) <p class="text-ink" data-items>{{ $for }}</p> @endif
                @include('partials.tax-breakdown', ['tax' => $payResult->taxBreakdown(), 'compact' => true, 'class' => 'text-sm text-ink'])
                <p class="mt-2 text-ink">
                    @if ($paidOk)
                        Cleared. You're owed it on Local Goods; hand over the order.
                    @elseif ($payResult->isUnderReview())
                        It's being held for a quick review, and no money moves until a BID admin decides. If they pay another way, press Cancel this payment; otherwise wait for the review.
                    @else
                        Cancelled: the credits went back to the customer. You aren't owed it.
                    @endif
                </p>
                @unless ($paidOk) <p class="mt-2"><x-status-badge :redemption="$payResult" /></p> @endunless
            </div>
        @endif

        @if ($heldAtCounter->isNotEmpty())
            {{-- Held in-person payments: if the customer paid another way, cancel so the hold can never be approved later. --}}
            <div class="mt-5">
                <h3 class="font-semibold text-ink">Held at the counter</h3>
                <p class="text-sm text-muted">Not completed yet: no money moves until a BID admin decides. If the customer paid another way, cancel it here.</p>
                <ul class="mt-2 divide-y divide-line/70 rounded-xl border-2 border-line">
                    @foreach ($heldAtCounter as $held)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="tabular font-semibold">{{ Money::format($held->amount_cents) }} · {{ $held->employee->initials() }}</p>
                                @if ($for = $held->itemsSummary()) <p class="text-sm break-words" data-items>{{ $for }}</p> @endif
                                @include('partials.tax-breakdown', ['tax' => $held->taxBreakdown(), 'compact' => true, 'class' => 'text-sm text-muted'])
                                <p class="text-sm text-muted">{{ $held->created_at->setTimezone('America/New_York')->format('M j, g:i A') }} · <x-badge tone="hold">Under review</x-badge></p>
                            </div>
                            @if ($held->mayBeCancelledBy(auth()->user()))
                                <form method="POST" action="{{ route('merchant.redemption.cancel', $held) }}" data-busy="Cancelling the payment…">
                                    @csrf
                                    <x-button type="submit" variant="secondary" size="sm" aria-label="Cancel this payment: {{ Money::format($held->amount_cents) }} from {{ $held->employee->initials() }}">Cancel this payment</x-button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($lookedUp)
            {{-- Step 2: check it, then confirm. The customer is shown by initials only. --}}
            <div class="mt-4" data-pay-review>
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                    <dt class="text-muted">Amount</dt><dd class="tabular font-display text-3xl font-semibold text-ink">{{ Money::format($lookedUp->amount_cents) }}</dd>
                    @if ($for = $lookedUp->itemsSummary())
                        <dt class="text-muted">Items</dt><dd class="text-ink" data-items>{{ $for }}</dd>
                    @endif
                    <dt class="text-muted">Customer</dt><dd class="tabular font-semibold text-ink">{{ $lookedUp->employee->initials() }}</dd>
                    <dt class="text-muted">Code expires</dt><dd class="text-ink">{{ $lookedUp->expires_at->setTimezone('America/New_York')->format('g:i A') }}</dd>
                </dl>
                @include('partials.tax-breakdown', ['tax' => $lookedUp->taxBreakdown(), 'class' => 'mt-3 max-w-xs rounded-xl bg-band p-3 text-sm text-ink'])
                @if ($figures)
                    @include('partials.merchant-fee', ['sale' => $lookedUp->amount_cents, 'fee' => $fee->centsOn($lookedUp->amount_cents), 'class' => 'mt-3 grid max-w-xs grid-cols-[1fr_auto] gap-x-3 rounded-xl bg-band p-3 text-sm text-ink'])
                @endif
                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    <form method="POST" action="{{ route('merchant.pay-code.confirm', $lookedUp) }}" data-busy="Checking with Redemption Fraud Detection…">
                        @csrf
                        <input type="hidden" name="fee_change" value="{{ $fee->changeId }}">
                        <x-button type="submit" variant="accent" size="lg" class="w-full">Confirm payment</x-button>
                    </form>
                    <form method="POST" action="{{ route('merchant.pay-code.decline', $lookedUp) }}" data-busy="Declining…">
                        @csrf
                        <x-button type="submit" variant="secondary" size="lg" class="w-full">Decline</x-button>
                    </form>
                </div>
                <form method="POST" action="{{ route('merchant.pay-code.clear') }}" data-busy="Clearing…" class="mt-2">
                    @csrf
                    <x-button type="submit" variant="ghost" size="sm">Enter a different code</x-button>
                </form>
            </div>
        @else
            {{-- Step 1: the customer's code. Judges get the same field, disabled and outside any form. --}}
            @can('take-payments')
                <form method="POST" action="{{ route('merchant.pay-code.lookup') }}" data-busy="Checking the code…" class="mt-4 space-y-3">
                    @csrf
                    @include('dashboards.partials.pay-code-field', ['disabled' => false])
                    <x-button type="submit" size="lg" class="w-full">Check code</x-button>
                </form>
            @else
                <div class="mt-4 space-y-3">
                    @include('dashboards.partials.pay-code-field', ['disabled' => true])
                    <x-button type="button" size="lg" class="w-full" disabled aria-describedby="take-payment-read-only">Check code</x-button>
                    <p id="take-payment-read-only" class="text-sm text-muted">Read-only: judges can't take payments.</p>
                </div>
            @endcan
            @cannot('take-payments')
                {{-- Judges: what the cashier sees after checking a code. A sample, not a real payment. --}}
                <div class="mt-5 rounded-2xl border-2 border-dashed border-line p-4">
                    <p class="text-sm font-semibold uppercase tracking-widest text-muted">Sample, after a code is checked</p>
                    @php $sampleTax = SalesTax::forItems([['name' => 'Latte', 'unit_price_cents' => 575, 'quantity' => 2, 'tax_category' => 'prepared_food']]); @endphp
                    <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                        <dt class="text-muted">Amount</dt><dd class="tabular font-display text-2xl font-semibold text-ink">{{ Money::format($sampleTax->totalCents) }}</dd>
                        <dt class="text-muted">Items</dt><dd class="text-ink">2× Latte</dd>
                        <dt class="text-muted">Customer</dt><dd class="font-semibold text-ink">A.B.</dd>
                    </dl>
                    @include('partials.tax-breakdown', ['tax' => $sampleTax, 'class' => 'mt-3 max-w-xs rounded-xl bg-band p-3 text-sm text-ink'])
                    <p class="mt-2 text-sm text-muted">Then Confirm payment (checked by Redemption Fraud Detection, like every redemption) or Decline.</p>
                </div>
            @endcannot
        @endif
    </x-card>

    <dl class="grid gap-3 sm:grid-cols-3">
        <x-card tone="night">
            <dt class="text-sm font-semibold text-night-muted">Owed to you now</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-night-accent">{{ Money::format($owedCents) }}</dd>
            <dd class="mt-1 text-sm text-night-muted">Cleared redemptions not yet paid out</dd>
            <dd class="mt-3 grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 border-t border-night-line/40 pt-3 text-sm text-on-night">
                <span>Owed via Stripe test mode</span><span class="tabular text-right font-semibold" data-owed="stripe">{{ Money::format($owedStripeCents) }}</span>
                <span>Owed, simulated</span><span class="tabular text-right font-semibold" data-owed="simulated">{{ Money::format($owedSimulatedCents) }}</span>
            </dd>
            <dd class="mt-3 text-sm text-night-muted" data-fee-now>Local Goods fee: {{ $figures ? $fee->label() : $fee->wordsLabel() }}</dd>
        </x-card>
        <div class="rounded-2xl bg-hold-bg p-5 sm:p-6">
            <dt class="text-sm font-semibold text-hold">Waiting for review, not owed yet</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-hold">{{ Money::format($heldCents) }}</dd>
            <dd class="mt-1 text-sm text-hold">
                {{ $heldCount }} {{ Str::plural('redemption', $heldCount) }} held by Redemption Fraud Detection. A person at the BID decides; only approved ones are owed.
            </dd>
        </div>
        <x-card>
            <dt class="text-sm font-semibold text-muted">Cleared sales this month (including tax){{ $monthRefundedCents > 0 ? ', net of refunds' : '' }}</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-primary" data-month-net>{{ Money::format($monthCents - $monthRefundedCents) }}</dd>
            @if ($monthRefundedCents > 0)
                {{-- Refunds (ADR 0028) count in the month they completed, like the BID impact figures. --}}
                <dd class="tabular mt-1 text-sm text-muted" data-month-refunds>Sales {{ Money::format($monthCents) }} · Refunds −{{ Money::format($monthRefundedCents) }} · Net {{ Money::format($monthCents - $monthRefundedCents) }}</dd>
            @endif
            <dd class="mt-1 text-sm text-muted">{{ $monthVisits }} cleared {{ Str::plural('visit', $monthVisits) }} · {{ Money::format($paidOutCents) }} paid out in all</dd>
        </x-card>
    </dl>

    {{-- DC sales tax on cleared menu orders (ADR 0027), for the merchant's records. Not tax advice; they file it. --}}
    <x-card as="section" id="tax" aria-labelledby="tax-heading" class="scroll-mt-24" data-tax-summary>
        <h2 id="tax-heading" class="text-2xl font-semibold text-primary">Tax collected</h2>
        <p class="mt-1 text-sm text-ink">For your records. Your business files and remits DC sales tax; Local Goods does not.</p>
        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            @foreach (['this_month' => 'This month', 'last_month' => 'Last month'] as $key => $title)
                @php $month = $taxSummary[$key]; @endphp
                <div>
                    <dt class="text-sm font-semibold text-muted">{{ $title }} ({{ $month['label'] }})</dt>
                    <dd class="tabular mt-1 font-display text-3xl font-semibold text-primary" data-tax-month="{{ $key }}">{{ Money::format($month['net_tax_cents']) }}</dd>
                    <dd class="text-sm text-muted">On {{ $month['orders'] }} cleared menu {{ Str::plural('order', $month['orders']) }}</dd>
                    @if ($month['refunds'] > 0)
                        {{-- Refunds (ADR 0028): their tax comes off in the month the refund completed. --}}
                        <dd class="tabular text-sm text-muted" data-tax-refunded="{{ $key }}">{{ Money::format($month['tax_cents']) }} added at checkout, less {{ Money::format($month['refunded_tax_cents']) }} refunded on {{ $month['refunds'] }} {{ Str::plural('refund', $month['refunds']) }}</dd>
                    @endif
                </div>
            @endforeach
        </dl>
        <details class="mt-4 text-sm">
            <summary class="cursor-pointer font-semibold text-primary">How we count this</summary>
            <p class="mt-1 text-muted">
                The DC sales tax added at checkout to cleared menu orders, by the date of the order in DC time, at the rate snapshotted on each one.
                Held and denied orders aren't counted. Typed-in amounts already include any tax you charged and aren't split out here.
                When menu items are refunded, their tax (from the order's own snapshot) comes off in the month the refund completed.
                Rates are DC's published rates, shown for display; check them and your categories with your accountant.
            </p>
        </details>
        <p class="mt-3"><a href="{{ route('merchant.redemptions.csv') }}" class="tap-target font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Download all redemptions (CSV, with subtotal, tax and total)</a></p>
    </x-card>

    <div class="grid gap-6 lg:grid-cols-5">
        <section id="received" aria-labelledby="received-heading" class="scroll-mt-24 lg:col-span-3">
            <h2 id="received-heading" class="text-2xl font-semibold text-primary">Redemptions received</h2>
            <p class="mt-1 text-sm text-muted">
                {{ $showAll ? 'All '.$receivedCount : 'The newest '.min($receivedCount, \App\Http\Controllers\Merchant\MerchantDashboardController::RECENT).' of '.$receivedCount }}, newest first. Customers are shown by initials only.
            </p>
            <x-card as="ul" padding="p-0" class="mt-3 divide-y divide-line/70">
                @forelse ($received as $redemption)
                    <li class="flex items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="tabular font-semibold">{{ $redemption->employee->initials() }} @if ($redemption->isGeneratedDemoActivity()) <x-demo-activity-badge /> @endif</p>
                            @if ($for = $redemption->itemsSummary())
                                <p class="text-sm break-words" data-items>{{ $for }}</p>
                            @endif
                            @include('partials.tax-breakdown', ['tax' => $redemption->taxBreakdown(), 'compact' => true, 'class' => 'text-sm text-muted'])
                            @if ($figures && $redemption->isApproved())
                                @include('partials.merchant-fee', ['sale' => $redemption->amount_cents, 'fee' => (int) $redemption->merchant_fee_cents])
                            @endif
                            <p class="text-sm text-muted">{{ $redemption->created_at->setTimezone('America/New_York')->format('M j, g:i A') }} <span data-channel>· {{ $redemption->channelLabel() }}</span></p>
                            @if ($redemption->scored_by !== 'seed')
                                <p class="text-sm text-muted" data-audit-trail>{{ implode(' → ', $redemption->auditTrail()) }}</p>
                            @endif
                            @include('partials.correction-request', [
                                'for' => 'redemption:'.$redemption->id, 'context' => \App\Support\CorrectionReasons::MERCHANT_REDEMPTION,
                                'correction' => $redemption->latestCorrection, 'canAsk' => $redemption->mayAskForRefund(),
                                'summary' => 'Customer was charged wrongly', 'action' => route('merchant.correction.redemption', $redemption),
                            ])
                        </div>
                        <div class="text-right">
                            <p @class(['tabular font-semibold', 'text-muted line-through' => $redemption->isRejected()])>{{ Money::format($redemption->amount_cents) }}</p>
                            @if ($redemption->isUnderReview())
                                <x-badge tone="hold" :wrap="true" class="mt-1 max-w-36">Waiting for review, not owed yet</x-badge>
                            @elseif ($redemption->isCancelled())
                                <x-badge tone="neutral" class="mt-1">{{ $redemption->cancelledLabel() }}, not owed</x-badge>
                            @elseif ($redemption->isRejected())
                                <x-badge tone="neutral" class="mt-1">Denied, not owed</x-badge>
                            @else
                                <x-badge tone="ok" class="mt-1">Cleared</x-badge>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-6 text-muted">No Local Goods redemptions yet. They'll appear here as soon as a customer pays with credits.</li>
                @endforelse
            </x-card>
            @if (! $showAll && $receivedCount > $received->count())
                <p class="mt-3"><a href="{{ route('merchant.dashboard', ['all' => 1]) }}#received" class="tap-target font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Show all {{ $receivedCount }} redemptions</a></p>
            @elseif ($showAll && $receivedCount > \App\Http\Controllers\Merchant\MerchantDashboardController::RECENT)
                <p class="mt-3"><a href="{{ route('merchant.dashboard') }}#received" class="font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Show the newest {{ \App\Http\Controllers\Merchant\MerchantDashboardController::RECENT }} only</a></p>
            @endif
        </section>

        <section aria-labelledby="payouts-heading" class="space-y-8 lg:col-span-2">
            <div id="stripe" class="scroll-mt-24">
                <h2 class="text-2xl font-semibold text-primary">Stripe account</h2>
                <x-card class="mt-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-semibold" data-onboarding="{{ $merchant->stripeOnboardingStatus() }}">{{ $merchant->stripeOnboardingLabel() }}</p>
                        <x-money-mode :mode="$merchant->stripe_account_id ? 'stripe' : null" :id="$merchant->stripe_account_id" />
                    </div>
                    <p class="mt-2 text-sm text-ink" data-fee-sentence>
                        @if ($fee->isNone())
                            Local Goods charges no fee on your sales right now.
                        @elseif ($figures)
                            Local Goods keeps a fee of {{ $fee->label() }}; Stripe transfers you the rest.
                        @else
                            Local Goods keeps a fee on each sale; Stripe transfers you the rest.
                        @endif
                    </p>
                    @if ($merchant->stripe_set_up_by_platform && $merchant->stripe_account_id)
                        <p class="mt-2 text-sm text-muted" data-platform-setup>
                            This fictional merchant's test account was set up by the Local Goods platform for the demo, with Stripe's test values.
                            Real merchants always onboard themselves through Stripe's Express pages.
                        </p>
                    @endif
                    @if (! $stripe)
                        <p class="mt-2 text-sm text-muted">Stripe isn't connected on this server, so there is nothing to onboard yet.</p>
                    @elseif ($merchant->isStripeOnboarded())
                        <p class="mt-2 text-sm text-muted">Stripe-funded cleared redemptions are transferred to this account as they clear.</p>
                    @else
                        <p class="mt-2 text-sm text-muted">
                            Until onboarding is complete, money owed through Stripe ({{ Money::format($owedStripeCents) }}) waits here. Stripe asks for the
                            business details on its own page; in test mode use Stripe's test values.
                        </p>
                        @if ($canOnboard)
                            <div class="mt-4 flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('merchant.stripe.onboard') }}" data-busy="Opening Stripe…">
                                    @csrf
                                    <x-button type="submit" variant="accent">{{ $merchant->stripe_account_id ? 'Continue Stripe onboarding' : 'Start Stripe onboarding' }} (test mode)</x-button>
                                </form>
                                @if ($merchant->stripe_account_id)
                                    <form method="POST" action="{{ route('merchant.stripe.sync') }}" data-busy="Checking with Stripe…">
                                        @csrf
                                        <x-button type="submit" size="sm">Check Stripe status</x-button>
                                    </form>
                                @endif
                            </div>
                        @else
                            <p class="mt-3 text-sm text-muted">Read-only: judges can't onboard.</p>
                        @endif
                    @endif
                </x-card>
            </div>

            <div>
            <h2 id="payouts-heading" class="text-2xl font-semibold text-primary">Payout history</h2>
            <p class="mt-1 text-sm text-muted">Each payout says how it was paid: a Stripe test-mode transfer (with its id), or simulated (recorded in the ledger, no money moved). Payouts are what you receive, after any Local Goods fee on each sale.</p>
            <x-card as="ul" padding="p-0" class="mt-3 divide-y divide-line/70">
                @forelse ($payouts as $payout)
                    <li class="flex items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ $payout->paid_at?->setTimezone('America/New_York')->format('D, M j, Y') ?? 'Not paid yet' }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                @if ($payout->isStripe())
                                    <x-badge :tone="match ($payout->status) { 'sent' => 'ok', 'failed', 'reversed' => 'hold', default => 'neutral' }">{{ match ($payout->status) {
                                        'owed' => 'Owed, waiting for onboarding', 'pending' => 'Sending', 'sent' => 'Transferred',
                                        'failed' => 'Not sent yet, still owed', 'reversed' => 'Reversed, owed again', default => ucfirst($payout->status) } }}</x-badge>
                                    <x-money-mode mode="stripe" :id="$payout->stripe_transfer_id" />
                                @else
                                    <x-money-mode mode="simulated" />
                                @endif
                            </div>
                            @if ($figures && $payout->redemption)
                                @include('partials.merchant-fee', ['sale' => $payout->amount_cents + $payout->fee_cents, 'fee' => $payout->fee_cents])
                            @endif
                            @if ($payout->redemption)
                                <p class="mt-1 text-sm text-muted" data-audit-trail>
                                    Redemption #{{ $payout->redemption->id }} ({{ $payout->redemption->employee->initials() }}): {{ implode(' → ', $payout->redemption->auditTrail()) }}
                                </p>
                            @endif
                            @if ($payout->failure_reason && $payout->status !== 'sent')
                                <p class="mt-1 text-sm text-hold">{{ $payout->failure_reason }}</p>
                            @endif
                            @if ($payout->isStripe())
                                @include('partials.correction-request', [
                                    'for' => 'payout:'.$payout->id, 'context' => \App\Support\CorrectionReasons::MERCHANT_PAYOUT,
                                    'correction' => $payout->latestCorrection, 'canAsk' => true,
                                    'summary' => 'I wasn\'t paid', 'action' => route('merchant.correction.payout', $payout),
                                ])
                            @endif
                        </div>
                        <p class="tabular font-semibold">{{ Money::format($payout->amount_cents) }}</p>
                    </li>
                @empty
                    <li class="px-5 py-6 text-muted">No payouts yet.</li>
                @endforelse
            </x-card>
            </div>
        </section>
    </div>
</div>
@endif
</x-app-shell>
