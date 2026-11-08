@php use App\Support\Money; @endphp

<x-app-shell title="Participation" active="participation"
             :context="($bid?->name ?? 'Golden Triangle').' BID · '.($bid?->city ?? 'Washington, DC')">
<div class="admin-desk space-y-14">
    <header>
        <x-station-sign as="p">{{ $bid?->name ?? 'Golden Triangle' }} BID · Participation</x-station-sign>
        <h1 class="page-title">Participation</h1>
        <p class="mt-3 text-2xl font-bold tracking-tight text-ink">Dollars kept in the district</p>
        <p class="page-lede">
            Who takes part in Local Goods and how much employer money was spent at independent businesses in the BID.
            Only cleared redemptions count; anything held by Redemption Fraud Detection waits for your decision first.
        </p>
    </header>

    <div>
    <dl class="grid gap-x-6 gap-y-10 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rule-top">
            <dt class="figure-label">Employers</dt>
            <dd class="money mt-2 text-4xl">{{ $employers }}</dd>
            <dd class="mt-1 text-sm text-muted">{{ $staff }} staff in all</dd>
        </div>
        <div class="rule-top">
            <dt class="figure-label">Employees active this month</dt>
            <dd class="money mt-2 text-4xl">{{ $activeEmployees }} <span class="text-xl text-muted">of {{ $enrolled }}</span></dd>
            <dd class="mt-1 text-sm text-muted">Enrolled employees with a cleared redemption since {{ $month->format('M') }} 1</dd>
        </div>
        <div class="rule-top">
            <dt class="figure-label">Participating merchants</dt>
            <dd class="money mt-2 text-4xl">{{ $merchants }}</dd>
            <dd class="mt-1 text-sm text-muted">Fictional demo businesses</dd>
        </div>
        <div class="rule-heavy pt-4">
            <dt class="figure-label">Spent at independent merchants</dt>
            <dd class="money mt-2 text-5xl text-primary">{{ Money::format($monthCents) }}</dd>
            <dd class="mt-1 text-sm text-muted">this month{{ $monthRefundedCents > 0 ? ' (net of refunds)' : '' }} · {{ Money::format($allTimeCents) }} since launch{{ $allTimeRefundedCents > 0 ? ' (net of refunds)' : '' }}</dd>
        </div>
    </dl>
    <p class="mt-6 max-w-[52rem] border-t border-line pt-3 text-sm text-muted">
        How we count this: cleared redemptions (approved by the AI or by a person here) at participating merchants.
        @if ($allTimeRefundedCents > 0)
            Refunds that completed come off in the month they completed ({{ Money::format($allTimeRefundedCents) }} since launch).
        @endif
        @if ($heldCents > 0)
            {{ Money::format($heldCents) }} more is held for review and not counted yet.
        @endif
    </p>
    </div>

    <section aria-labelledby="roster-heading" class="rule-heavy">
        <h2 id="roster-heading" class="stop-subtitle">Merchant roster</h2>
        <p class="mt-1 text-sm text-muted">
            The businesses that accept Local Goods credits. All are fictional in this demo; real businesses under review are on
            <a href="{{ route('bid-admin.merchants') }}" class="font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover">Merchant eligibility</a>.
        </p>

        @if ($roster->isEmpty())
            <p class="ledger-note mt-4 text-muted">No merchants take part yet. Approved businesses will be listed here once they join.</p>
        @else
            <x-rtable class="mt-3" label="Participating merchants table">
                <table data-rtable="stack" class="w-full text-left">
                    <caption class="sr-only">Participating merchants with this month's cleared sales, what they are owed through Stripe test mode and simulated, their last payout and Stripe onboarding</caption>
                    <thead class="border-b border-line/70 text-sm text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold">Merchant</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Category</th>
                            <th scope="col" class="px-5 py-3 text-right font-semibold">Visits this month</th>
                            <th scope="col" class="px-5 py-3 text-right font-semibold">Sales this month</th>
                            <th scope="col" class="px-5 py-3 text-right font-semibold">Owed via Stripe test mode</th>
                            <th scope="col" class="px-5 py-3 text-right font-semibold">Owed, simulated</th>
                            <th scope="col" class="px-5 py-3 text-right font-semibold">Last payout</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Stripe onboarding</th>
                        </tr>
                    </thead>
                    <tbody class="tabular divide-y divide-line/70">
                        @foreach ($roster as $merchant)
                            <tr>
                                <th scope="row" data-primary class="px-5 py-3 font-semibold">{{ $merchant->name }}<span class="block text-sm font-normal text-muted">{{ $merchant->address }}</span></th>
                                <td data-label="Category" class="px-5 py-3 capitalize">{{ $merchant->category }}</td>
                                <td data-label="Visits this month" class="px-5 py-3 text-right">{{ $merchant->month_visits }}</td>
                                <td data-label="Sales this month" class="px-5 py-3 text-right">{{ Money::format((int) $merchant->month_cents) }}@if ($merchant->month_refunded_cents > 0)<span class="block text-sm text-muted" data-net-of-refunds>net of refunds ({{ Money::format($merchant->month_refunded_cents) }})</span>@endif</td>
                                <td data-label="Owed via Stripe test mode" class="px-5 py-3 text-right">{{ Money::format((int) $merchant->owed_stripe_cents) }}</td>
                                <td data-label="Owed, simulated" class="px-5 py-3 text-right">{{ Money::format((int) $merchant->owed_cents - (int) $merchant->owed_stripe_cents) }}</td>
                                <td data-label="Last payout" class="px-5 py-3 text-right">{{ $merchant->last_payout_at ? \Illuminate\Support\Carbon::parse($merchant->last_payout_at)->setTimezone('America/New_York')->format('M j') : 'None yet' }}</td>
                                <td data-label="Stripe onboarding" class="px-5 py-3 text-sm" data-onboarding="{{ $merchant->stripeOnboardingStatus() }}">
                                    {{ $merchant->stripeOnboardingLabel() }}
                                    @if ($merchant->stripe_account_id)<code class="wrap-anywhere-safe block font-mono text-xs text-muted">{{ $merchant->stripe_account_id }}</code>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
            <p class="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted">
                <x-money-mode />
                @if ($stripe)
                    Redemptions funded by Stripe-paid credits are transferred through Stripe (test mode) once the merchant finishes onboarding. Seeded history stays simulated.
                @else
                    Stripe isn't connected on this server: payouts are simulated, recorded in the ledger with no real transfer.
                @endif
            </p>
        @endif
    </section>
</div>
</x-app-shell>
