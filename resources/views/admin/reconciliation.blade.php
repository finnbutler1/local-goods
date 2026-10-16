@php use App\Support\Money; @endphp

<x-app-shell title="Reconciliation" active="dashboard" context="Local Goods platform">
<div class="space-y-8">
    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Platform admin · Money check</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Does the ledger agree with Stripe?</h1>
        <p class="mt-3 text-lg text-muted">
            Stripe holds and moves the money; Local Goods keeps the ledger of who is owed what. This puts the two side by side for
            Stripe-funded activity only, since the last Reset demo ({{ $report['since']->setTimezone('America/New_York')->format('M j, Y g:i A') }}).
            Simulated purchases and payouts never touched Stripe, so they are not part of the check.
            Only this environment's Stripe objects are counted (<span class="font-mono text-base">{{ $report['environment'] }}</span>).
        </p>
        <x-money-mode class="mt-3" />
    </header>

    <div aria-live="polite" class="empty:hidden">
        @if (session('status'))
            <p class="rounded-2xl border-2 border-ok bg-ok-bg p-4 font-semibold text-ok">{{ session('status') }}</p>
        @endif
        @if (session('error'))
            <p class="rounded-2xl border-2 border-hold bg-hold-bg p-4 font-semibold text-hold">{{ session('error') }}</p>
        @endif
    </div>

    @if (! $report['active'])
        <x-card class="max-w-3xl">
            <h2 class="text-xl font-semibold">Stripe test mode is off</h2>
            <p class="mt-2 text-muted">{{ $report['problem'] }}</p>
            <p class="mt-2 text-sm text-muted">Set STRIPE_KEY, STRIPE_SECRET and STRIPE_WEBHOOK_SECRET (test keys) to switch it on. See docs/STRIPE.md.</p>
        </x-card>
    @elseif ($report['error'])
        <p role="alert" class="rounded-2xl border-2 border-hold bg-hold-bg p-4 font-semibold text-hold">Couldn't read from Stripe: {{ $report['error'] }}</p>
    @else
        @if ($report['mismatches'] === [])
            <p class="rounded-2xl border-2 border-ok bg-ok-bg p-4 font-semibold text-ok" data-reconciled="yes">
                <span aria-hidden="true">✓</span> Everything matches: every Stripe-paid purchase and every Stripe transfer in the ledger is in Stripe, for the same amount, and nothing extra.
            </p>
        @else
            <section role="alert" aria-labelledby="mismatch-heading" class="rounded-2xl border-2 border-hold bg-hold-bg p-5" data-reconciled="no">
                <h2 id="mismatch-heading" class="text-xl font-semibold text-hold"><span aria-hidden="true">⚑</span> {{ count($report['mismatches']) }} {{ Str::plural('thing', count($report['mismatches'])) }} don't match</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-hold">
                    @foreach ($report['mismatches'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <x-card padding="p-0" class="overflow-x-auto">
            <table class="w-full text-left">
                <caption class="px-5 pt-5 text-left text-xl font-semibold sm:px-6">Ledger next to Stripe</caption>
                <thead class="border-b border-line/70 text-sm text-muted">
                    <tr>
                        <th scope="col" class="px-5 py-3 font-semibold sm:px-6">What</th>
                        <th scope="col" class="px-5 py-3 text-right font-semibold">Local Goods ledger</th>
                        <th scope="col" class="px-5 py-3 text-right font-semibold">Stripe</th>
                        <th scope="col" class="px-5 py-3 font-semibold">Match?</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/70 tabular">
                    @foreach ([
                        ['Purchases paid by card', $report['ledger']['purchase_cents'], $report['ledger']['purchases'], $report['stripe']['purchase_cents'], $report['stripe']['purchases']],
                        ['Transfers to merchants', $report['ledger']['transfer_cents'], $report['ledger']['transfers'], $report['stripe']['transfer_cents'], $report['stripe']['transfers']],
                    ] as [$label, $ours, $ourCount, $theirs, $theirCount])
                        <tr>
                            <th scope="row" class="px-5 py-3 font-semibold sm:px-6">{{ $label }}</th>
                            <td class="px-5 py-3 text-right">{{ Money::format($ours) }} <span class="text-sm text-muted">({{ $ourCount }})</span></td>
                            <td class="px-5 py-3 text-right">{{ Money::format($theirs) }} <span class="text-sm text-muted">({{ $theirCount }})</span></td>
                            <td class="px-5 py-3">
                                @if ($ours === $theirs && $ourCount === $theirCount)
                                    <x-badge tone="ok">Matches</x-badge>
                                @else
                                    <x-badge tone="hold">Doesn't match</x-badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr>
                        <th scope="row" class="px-5 py-3 font-semibold sm:px-6">Balance held by Stripe</th>
                        <td class="px-5 py-3 text-right">{{ Money::format($report['expected_balance_cents']) }} <span class="block text-sm text-muted">paid minus transferred</span></td>
                        <td class="px-5 py-3 text-right">{{ Money::format($report['stripe']['balance_cents']) }} <span class="block text-sm text-muted">{{ Money::format($report['stripe']['available_cents']) }} available now</span></td>
                        <td class="px-5 py-3"><x-badge tone="neutral">For information</x-badge></td>
                    </tr>
                </tbody>
            </table>
        </x-card>

        @if ($report['notes'] !== [])
            <x-card as="section" aria-labelledby="notes-heading">
                <h2 id="notes-heading" class="text-xl font-semibold">Worth knowing (not mismatches)</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-muted">
                    @foreach ($report['notes'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        @if ($report['other']['lines'] !== [])
            <x-card as="section" aria-labelledby="other-env-heading" data-other-environment>
                <h2 id="other-env-heading" class="text-xl font-semibold">From another environment, not counted</h2>
                <p class="mt-2 text-muted">
                    {{ $report['other']['payments'] }} {{ Str::plural('payment', $report['other']['payments']) }} ({{ Money::format($report['other']['payment_cents']) }})
                    and {{ $report['other']['transfers'] }} {{ Str::plural('transfer', $report['other']['transfers']) }} ({{ Money::format($report['other']['transfer_cents']) }})
                    in this Stripe account were made by another copy of Local Goods (for example local development sharing the sandbox), or before objects were tagged, and this database has no record of them. They are left out of every total above.
                </p>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-muted">
                    @foreach ($report['other']['lines'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        <form method="POST" action="{{ route('admin.reconciliation.retry') }}">
            @csrf
            <x-button type="submit">Retry owed transfers</x-button>
            <p class="mt-2 text-sm text-muted">Sends Stripe-funded amounts still owed to onboarded merchants. Simulated amounts are never sent.</p>
        </form>
    @endif

    <x-card as="section" aria-labelledby="simulated-heading">
        <h2 id="simulated-heading" class="flex flex-wrap items-center gap-2 text-xl font-semibold">Not part of this check <x-money-mode mode="simulated" /></h2>
        <p class="mt-2 text-muted">
            {{ $report['simulated']['purchases'] }} simulated {{ Str::plural('purchase', $report['simulated']['purchases']) }} ({{ Money::format($report['simulated']['purchase_cents']) }})
            and {{ $report['simulated']['payouts'] }} simulated {{ Str::plural('payout', $report['simulated']['payouts']) }} ({{ Money::format($report['simulated']['payout_cents']) }}):
            seeded demo history and anything paid while Stripe was off. They are in the ledger with no money behind them.
        </p>
    </x-card>
</div>
</x-app-shell>
