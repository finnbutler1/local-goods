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
            Customers from employers in the BID pay with Local Goods credits. You're owed a redemption once it clears,
            and paid out every Friday. Payouts are simulated until Stripe test mode arrives in a later build: no real money moves yet.
        </p>
    </header>

    <dl class="grid gap-3 sm:grid-cols-3">
        <x-card tone="forest">
            <dt class="text-sm font-semibold text-sage">Owed to you now</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-amber">{{ Money::format($owedCents) }}</dd>
            <dd class="mt-1 text-sm text-sage">Cleared redemptions not yet paid out</dd>
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
        <section aria-labelledby="received-heading" class="lg:col-span-3">
            <h2 id="received-heading" class="text-2xl font-semibold text-forest">Redemptions received</h2>
            <p class="mt-1 text-sm text-muted">Newest first. Customers are shown by initials only.</p>
            <x-card as="ul" padding="p-0" class="mt-3 divide-y divide-line/70">
                @forelse ($received as $redemption)
                    <li class="flex items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="tabular font-semibold">{{ $redemption->employee->initials() }}</p>
                            <p class="text-sm text-muted">{{ $redemption->created_at->setTimezone('America/New_York')->format('M j, g:i A') }}</p>
                        </div>
                        <div class="text-right">
                            <p @class(['tabular font-semibold', 'text-muted line-through' => $redemption->isRejected()])>{{ Money::format($redemption->amount_cents) }}</p>
                            @if ($redemption->isUnderReview())
                                <x-badge tone="hold" class="mt-1">Waiting for review, not owed yet</x-badge>
                            @elseif ($redemption->isRejected())
                                <x-badge tone="neutral" class="mt-1">Rejected, not owed</x-badge>
                            @else
                                <x-badge tone="ok" class="mt-1">Cleared</x-badge>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-6 text-muted">No Local Goods redemptions yet. They'll appear here as soon as a customer pays with credits.</li>
                @endforelse
            </x-card>
        </section>

        <section aria-labelledby="payouts-heading" class="lg:col-span-2">
            <h2 id="payouts-heading" class="text-2xl font-semibold text-forest">Payout history</h2>
            <p class="mt-1 text-sm text-muted">Simulated until Stripe test mode. Each payout is recorded in the ledger, with no bank transfer behind it.</p>
            <x-card as="ul" padding="p-0" class="mt-3 divide-y divide-line/70">
                @forelse ($payouts as $payout)
                    <li class="flex items-center justify-between gap-3 px-5 py-3">
                        <div>
                            <p class="font-semibold">{{ $payout->paid_at?->setTimezone('America/New_York')->format('D, M j, Y') ?? 'Not paid yet' }}</p>
                            <x-badge tone="neutral" class="mt-1">{{ $payout->status === \App\Models\MerchantPayout::STATUS_SIMULATED ? 'Simulated until Stripe test mode' : ucfirst($payout->status) }}</x-badge>
                        </div>
                        <p class="tabular font-semibold">{{ Money::format($payout->amount_cents) }}</p>
                    </li>
                @empty
                    <li class="px-5 py-6 text-muted">No payouts yet. Cleared redemptions are paid out every Friday.</li>
                @endforelse
            </x-card>
        </section>
    </div>
</div>
@endif
</x-app-shell>
