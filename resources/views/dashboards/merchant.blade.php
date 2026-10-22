@php use App\Support\Money; @endphp

<x-app-shell title="Merchant dashboard" active="dashboard"
             :context="($merchant?->name ?? 'Merchant').' · Golden Triangle BID'">
@if ($merchant === null)
    <x-card as="section" aria-labelledby="empty-heading" class="border-2 border-dashed border-line ring-0! text-center" padding="px-6 py-12">
        <h1 id="empty-heading" class="text-2xl font-semibold text-forest">No business is linked to this login yet</h1>
        <p class="mx-auto mt-2 max-w-md text-muted">Once the BID adds your business to Local Goods, your sales and payouts appear here. Ask the BID team if you expected to see them.</p>
    </x-card>
@else
<div class="space-y-10">
    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Merchant dashboard · {{ $merchant->name }}</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Your Local Goods sales</h1>
        <p class="mt-3 text-lg text-muted">
            Customers from employers in the BID pay with Local Goods credits. You're owed a redemption once it clears.
            @if ($stripe)
                Redemptions paid for with Stripe-paid credits are sent to your Stripe account (test mode) as they clear; older, simulated history stays simulated.
            @else
                Stripe isn't connected on this server, so payouts are simulated: recorded in the ledger, no money moves.
            @endif
        </p>
        <x-money-mode class="mt-3" />
    </header>

    <dl class="grid gap-3 sm:grid-cols-3">
        <x-card tone="forest">
            <dt class="text-sm font-semibold text-sage">Owed to you now</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-amber">{{ Money::format($owedCents) }}</dd>
            <dd class="mt-1 text-sm text-sage">Cleared redemptions not yet paid out</dd>
            <dd class="mt-3 grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 border-t border-sage/40 pt-3 text-sm text-cream">
                <span>Owed via Stripe test mode</span><span class="tabular text-right font-semibold" data-owed="stripe">{{ Money::format($owedStripeCents) }}</span>
                <span>Owed, simulated</span><span class="tabular text-right font-semibold" data-owed="simulated">{{ Money::format($owedSimulatedCents) }}</span>
            </dd>
        </x-card>
        <div class="rounded-2xl bg-hold-bg p-5 sm:p-6">
            <dt class="text-sm font-semibold text-hold">Waiting for review, not owed yet</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-hold">{{ Money::format($heldCents) }}</dd>
            <dd class="mt-1 text-sm text-hold">
                {{ $heldCount }} {{ Str::plural('redemption', $heldCount) }} held by Redemption Fraud Detection. A person at the BID decides; only approved ones are owed.
            </dd>
        </div>
        <x-card>
            <dt class="text-sm font-semibold text-muted">Cleared sales this month</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-forest">{{ Money::format($monthCents) }}</dd>
            <dd class="mt-1 text-sm text-muted">{{ $monthVisits }} cleared {{ Str::plural('visit', $monthVisits) }} · {{ Money::format($paidOutCents) }} paid out in all</dd>
        </x-card>
    </dl>

    <div class="grid gap-6 lg:grid-cols-5">
        <section id="received" aria-labelledby="received-heading" class="scroll-mt-24 lg:col-span-3">
            <h2 id="received-heading" class="text-2xl font-semibold text-forest">Redemptions received</h2>
            <p class="mt-1 text-sm text-muted">
                {{ $showAll ? 'All '.$receivedCount : 'The newest '.min($receivedCount, \App\Http\Controllers\Merchant\MerchantDashboardController::RECENT).' of '.$receivedCount }}, newest first. Customers are shown by initials only.
            </p>
            <x-card as="ul" padding="p-0" class="mt-3 divide-y divide-line/70">
                @forelse ($received as $redemption)
                    <li class="flex items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="tabular font-semibold">{{ $redemption->employee->initials() }}</p>
                            @if ($for = $redemption->itemsSummary())
                                <p class="text-sm break-words" data-items>{{ $for }}</p>
                            @endif
                            <p class="text-sm text-muted">{{ $redemption->created_at->setTimezone('America/New_York')->format('M j, g:i A') }}</p>
                            @if ($redemption->scored_by !== 'seed')
                                <p class="text-sm text-muted" data-audit-trail>{{ implode(' → ', $redemption->auditTrail()) }}</p>
                            @endif
                        </div>
                        <div class="text-right">
                            <p @class(['tabular font-semibold', 'text-muted line-through' => $redemption->isRejected()])>{{ Money::format($redemption->amount_cents) }}</p>
                            @if ($redemption->isUnderReview())
                                <x-badge tone="hold" class="mt-1">Waiting for review, not owed yet</x-badge>
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
                <p class="mt-3"><a href="{{ route('merchant.dashboard', ['all' => 1]) }}#received" class="font-semibold text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">Show all {{ $receivedCount }} redemptions</a></p>
            @elseif ($showAll && $receivedCount > \App\Http\Controllers\Merchant\MerchantDashboardController::RECENT)
                <p class="mt-3"><a href="{{ route('merchant.dashboard') }}#received" class="font-semibold text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">Show the newest {{ \App\Http\Controllers\Merchant\MerchantDashboardController::RECENT }} only</a></p>
            @endif
        </section>

        <section aria-labelledby="payouts-heading" class="space-y-8 lg:col-span-2">
            <div id="stripe" class="scroll-mt-24">
                <h2 class="text-2xl font-semibold text-forest">Stripe account</h2>
                <x-card class="mt-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-semibold" data-onboarding="{{ $merchant->stripeOnboardingStatus() }}">{{ $merchant->stripeOnboardingLabel() }}</p>
                        <x-money-mode :mode="$merchant->stripe_account_id ? 'stripe' : null" :id="$merchant->stripe_account_id" />
                    </div>
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
            <h2 id="payouts-heading" class="text-2xl font-semibold text-forest">Payout history</h2>
            <p class="mt-1 text-sm text-muted">Each payout says how it was paid: a Stripe test-mode transfer (with its id), or simulated (recorded in the ledger, no money moved).</p>
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
                            @if ($payout->redemption)
                                <p class="mt-1 text-sm text-muted" data-audit-trail>
                                    Redemption #{{ $payout->redemption->id }} ({{ $payout->redemption->employee->initials() }}): {{ implode(' → ', $payout->redemption->auditTrail()) }}
                                </p>
                            @endif
                            @if ($payout->failure_reason && $payout->status !== 'sent')
                                <p class="mt-1 text-sm text-hold">{{ $payout->failure_reason }}</p>
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
