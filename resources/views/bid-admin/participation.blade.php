@php use App\Support\Money; @endphp

<x-app-shell title="Participation" active="participation"
             :context="($bid?->name ?? 'Golden Triangle').' BID · '.($bid?->city ?? 'Washington, DC')">
<div class="space-y-10">
    <header class="max-w-3xl">
        <x-eyebrow class="text-accent-ink">{{ $bid?->name ?? 'Golden Triangle' }} BID · Participation</x-eyebrow>
        <h1 class="mt-1 text-4xl font-semibold text-primary sm:text-5xl">Dollars kept in the district</h1>
        <p class="mt-3 text-lg text-muted">
            Who takes part in Local Goods and how much employer money was spent at independent businesses in the BID.
            Only cleared redemptions count; anything held by Redemption Fraud Detection waits for your decision first.
        </p>
    </header>

    <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-card>
            <dt class="text-sm font-semibold text-muted">Employers</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-primary">{{ $employers }}</dd>
            <dd class="mt-1 text-sm text-muted">{{ $staff }} staff in all</dd>
        </x-card>
        <x-card>
            <dt class="text-sm font-semibold text-muted">Employees active this month</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-primary">{{ $activeEmployees }} <span class="text-xl text-muted">of {{ $enrolled }}</span></dd>
            <dd class="mt-1 text-sm text-muted">Enrolled employees with a cleared redemption since {{ $month->format('M') }} 1</dd>
        </x-card>
        <x-card>
            <dt class="text-sm font-semibold text-muted">Participating merchants</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-primary">{{ $merchants }}</dd>
            <dd class="mt-1 text-sm text-muted">Fictional demo businesses</dd>
        </x-card>
        <x-card tone="night">
            <dt class="text-sm font-semibold text-night-muted">Spent at independent merchants</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-night-accent">{{ Money::format($monthCents) }}</dd>
            <dd class="mt-1 text-sm text-night-muted">this month · {{ Money::format($allTimeCents) }} since launch</dd>
        </x-card>
    </dl>
    <p class="-mt-6 text-sm text-muted">
        How we count this: cleared redemptions (approved by the AI or by a person here) at participating merchants.
        @if ($heldCents > 0)
            {{ Money::format($heldCents) }} more is held for review and not counted yet.
        @endif
    </p>

    <section aria-labelledby="roster-heading">
        <h2 id="roster-heading" class="text-2xl font-semibold text-primary">Merchant roster</h2>
        <p class="mt-1 text-sm text-muted">
            The businesses that accept Local Goods credits. All are fictional in this demo; real businesses under review are on
            <a href="{{ route('bid-admin.merchants') }}" class="font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Merchant eligibility</a>.
        </p>

        @if ($roster->isEmpty())
            <x-card class="mt-3 text-muted">No merchants take part yet. Approved businesses will be listed here once they join.</x-card>
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
                                <td data-label="Sales this month" class="px-5 py-3 text-right">{{ Money::format((int) $merchant->month_cents) }}</td>
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
