@php use App\Support\Money; @endphp

<x-app-shell title="Participation" active="participation"
             :context="($bid?->name ?? 'Golden Triangle').' BID · '.($bid?->city ?? 'Washington, DC')">
<div class="space-y-10">
    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">{{ $bid?->name ?? 'Golden Triangle' }} BID · Participation</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Dollars kept in the district</h1>
        <p class="mt-3 text-lg text-muted">
            Who takes part in Local Goods and how much employer money was spent at independent businesses in the BID.
            Only cleared redemptions count; anything held by Redemption Fraud Detection waits for your decision first.
        </p>
    </header>

    <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-card>
            <dt class="text-sm font-semibold text-muted">Employers</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-forest">{{ $employers }}</dd>
            <dd class="mt-1 text-sm text-muted">{{ $staff }} staff in all</dd>
        </x-card>
        <x-card>
            <dt class="text-sm font-semibold text-muted">Employees active this month</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-forest">{{ $activeEmployees }} <span class="text-xl text-muted">of {{ $enrolled }}</span></dd>
            <dd class="mt-1 text-sm text-muted">Enrolled employees with a cleared redemption since {{ $month->format('M') }} 1</dd>
        </x-card>
        <x-card>
            <dt class="text-sm font-semibold text-muted">Participating merchants</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-forest">{{ $merchants }}</dd>
            <dd class="mt-1 text-sm text-muted">Fictional demo businesses</dd>
        </x-card>
        <x-card tone="forest">
            <dt class="text-sm font-semibold text-sage">Dollars recirculated</dt>
            <dd class="tabular mt-2 font-display text-4xl font-semibold text-amber">{{ Money::format($monthCents) }}</dd>
            <dd class="mt-1 text-sm text-sage">this month · {{ Money::format($allTimeCents) }} since launch</dd>
        </x-card>
    </dl>
    <p class="-mt-6 text-sm text-muted">
        How we count this: cleared redemptions (approved by the AI or by a person here) at participating merchants.
        @if ($heldCents > 0)
            {{ Money::format($heldCents) }} more is held for review and not counted yet.
        @endif
    </p>

    <section aria-labelledby="roster-heading">
        <h2 id="roster-heading" class="text-2xl font-semibold text-forest">Merchant roster</h2>
        <p class="mt-1 text-sm text-muted">
            The businesses that accept Local Goods credits. All are fictional in this demo; real businesses under review are on
            <a href="{{ route('bid-admin.merchants') }}" class="font-semibold text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">Merchant eligibility</a>.
        </p>

        @if ($roster->isEmpty())
            <x-card class="mt-3 text-muted">No merchants take part yet. Approved businesses will be listed here once they join.</x-card>
        @else
            {{-- Cards on phones, a table from sm up. --}}
            <ul class="mt-3 space-y-3 sm:hidden">
                @foreach ($roster as $merchant)
                    <x-card as="li" padding="p-4">
                        <p class="font-semibold">{{ $merchant->name }}</p>
                        <p class="text-sm text-muted"><span class="capitalize">{{ $merchant->category }}</span> · {{ $merchant->address }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                            <dt class="text-muted">This month</dt><dd class="tabular text-right">{{ Money::format((int) $merchant->month_cents) }} · {{ $merchant->month_visits }} {{ Str::plural('visit', $merchant->month_visits) }}</dd>
                            <dt class="text-muted">Owed via Stripe test mode</dt><dd class="tabular text-right">{{ Money::format((int) $merchant->owed_stripe_cents) }}</dd>
                            <dt class="text-muted">Owed, simulated</dt><dd class="tabular text-right">{{ Money::format((int) $merchant->owed_cents - (int) $merchant->owed_stripe_cents) }}</dd>
                            <dt class="text-muted">Stripe</dt><dd class="text-right">{{ $merchant->stripeOnboardingLabel() }}@if ($merchant->stripe_account_id)<code class="block break-all font-mono text-xs text-muted">{{ $merchant->stripe_account_id }}</code>@endif</dd>
                            <dt class="text-muted">Last payout</dt><dd class="text-right">{{ $merchant->last_payout_at ? \Illuminate\Support\Carbon::parse($merchant->last_payout_at)->setTimezone('America/New_York')->format('M j') : 'None yet' }}</dd>
                        </dl>
                    </x-card>
                @endforeach
            </ul>
            <x-card padding="p-0" class="mt-3 hidden overflow-x-auto sm:block">
                <table class="w-full text-left">
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
                                <th scope="row" class="px-5 py-3 font-semibold">{{ $merchant->name }}<span class="block text-sm font-normal text-muted">{{ $merchant->address }}</span></th>
                                <td class="px-5 py-3 capitalize">{{ $merchant->category }}</td>
                                <td class="px-5 py-3 text-right">{{ $merchant->month_visits }}</td>
                                <td class="px-5 py-3 text-right">{{ Money::format((int) $merchant->month_cents) }}</td>
                                <td class="px-5 py-3 text-right">{{ Money::format((int) $merchant->owed_stripe_cents) }}</td>
                                <td class="px-5 py-3 text-right">{{ Money::format((int) $merchant->owed_cents - (int) $merchant->owed_stripe_cents) }}</td>
                                <td class="px-5 py-3 text-right">{{ $merchant->last_payout_at ? \Illuminate\Support\Carbon::parse($merchant->last_payout_at)->setTimezone('America/New_York')->format('M j') : 'None yet' }}</td>
                                <td class="px-5 py-3 text-sm" data-onboarding="{{ $merchant->stripeOnboardingStatus() }}">
                                    {{ $merchant->stripeOnboardingLabel() }}
                                    @if ($merchant->stripe_account_id)<code class="block break-all font-mono text-xs text-muted">{{ $merchant->stripe_account_id }}</code>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
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
