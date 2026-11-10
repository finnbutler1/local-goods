@php use App\Support\Money; @endphp

<x-app-shell title="Reconciliation" active="reconciliation" context="Local Goods platform">
<div class="admin-desk space-y-12">
    <header>
        <x-station-sign as="p">Platform admin · Money check</x-station-sign>
        <h1 class="page-title">Reconciliation</h1>
        <p class="mt-3 text-2xl font-bold tracking-tight text-ink">Does the ledger agree with Stripe?</p>
        <p class="page-lede">
            Stripe holds and moves the money; Local Goods keeps the ledger of who is owed what. This puts the two side by side for
            Stripe-funded activity only, since the last Reset demo ({{ $report['since']->setTimezone('America/New_York')->format('M j, Y g:i A') }}).
            Simulated purchases and payouts never touched Stripe, so they are not part of the check.
            Only this environment's Stripe objects are counted (<span class="font-mono text-base">{{ $report['environment'] }}</span>).
        </p>
        <x-money-mode class="mt-3" />
        <p class="mt-3">
            <a href="{{ route('money') }}" class="tap-target font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Where the money is</a>:
            customer funds and operating funds, and the solvency check over the whole ledger, simulated money included.
        </p>
    </header>

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

        <x-rtable label="Ledger next to Stripe table">
            <table data-rtable="stack" class="w-full text-left">
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
                        // Refunds to employers' cards (ADR 0028): shown once there are any.
                        ...($report['ledger']['refunds'] + $report['stripe']['refunds'] > 0
                            ? [['Refunds to employers\' cards', $report['ledger']['refund_cents'], $report['ledger']['refunds'], $report['stripe']['refund_cents'], $report['stripe']['refunds']]] : []),
                    ] as [$label, $ours, $ourCount, $theirs, $theirCount])
                        <tr>
                            <th scope="row" data-primary class="px-5 py-3 font-semibold sm:px-6">{{ $label }}</th>
                            <td data-label="Local Goods ledger" class="px-5 py-3 text-right">
                                {{ Money::format($ours) }} <span class="text-sm text-muted">({{ $ourCount }})</span>
                                @if ($loop->first && $report['ledger']['fee_cents'] > 0)
                                    {{-- Only when the platform fee is on (ADR 0021). --}}
                                    <span class="block text-sm text-muted" data-fee-split>credits {{ Money::format($report['ledger']['credit_cents']) }} + platform fees {{ Money::format($report['ledger']['fee_cents']) }}</span>
                                @endif
                                @if ($loop->index === 1 && $report['ledger']['refund_reversal_cents'] > 0)
                                    <span class="block text-sm text-muted" data-refund-reversals>after {{ Money::format($report['ledger']['refund_reversal_cents']) }} pulled back for refunds</span>
                                @endif
                            </td>
                            <td data-label="Stripe" class="px-5 py-3 text-right">{{ Money::format($theirs) }} <span class="text-sm text-muted">({{ $theirCount }})</span></td>
                            <td data-label="Match?" class="px-5 py-3">
                                @if ($ours === $theirs && $ourCount === $theirCount)
                                    <x-badge tone="ok">Matches</x-badge>
                                @else
                                    <x-badge tone="hold">Doesn't match</x-badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr>
                        <th scope="row" data-primary class="px-5 py-3 font-semibold sm:px-6">Balance held by Stripe</th>
                        <td data-label="Local Goods ledger" class="px-5 py-3 text-right">{{ Money::format($report['expected_balance_cents']) }} <span class="block text-sm text-muted">paid minus transferred{{ $report['ledger']['refund_cents'] > 0 ? ' and refunded' : '' }}</span>
                            @if ($report['ledger']['merchant_fee_cents'] > 0)
                                {{-- Only when the merchant fee is on (ADR 0029): transfers are net of it, so it is already in this balance. --}}
                                <span class="block text-sm text-muted" data-merchant-fees>includes {{ Money::format($report['ledger']['merchant_fee_cents']) }} Local Goods fees kept on merchant sales</span>
                            @endif
                        </td>
                        <td data-label="Stripe" class="px-5 py-3 text-right">{{ Money::format($report['stripe']['balance_cents']) }} <span class="block text-sm text-muted">{{ Money::format($report['stripe']['available_cents']) }} available now</span></td>
                        <td data-label="Match?" class="px-5 py-3"><x-badge tone="neutral">For information</x-badge></td>
                    </tr>
                </tbody>
            </table>
        </x-rtable>
        @if ($transfersByChannel->isNotEmpty())
            <p class="text-sm text-muted" data-transfers-by-channel>
                Sent transfers by how the customer paid:
                {{ collect([\App\Models\Redemption::CHANNEL_TO_GO => 'to go', \App\Models\Redemption::CHANNEL_IN_PERSON => 'in person'])
                    ->map(fn ($label, $channel) => (int) ($transfersByChannel[$channel]->transfers ?? 0).' '.$label.' ('.Money::format((int) ($transfersByChannel[$channel]->cents ?? 0)).')')
                    ->implode(', ') }}.
                Display only: the check above doesn't use it.
            </p>
        @endif

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
                    and {{ $report['other']['transfers'] }} {{ Str::plural('transfer', $report['other']['transfers']) }} ({{ Money::format($report['other']['transfer_cents']) }})@if ($report['other']['refunds'] > 0), plus {{ $report['other']['refunds'] }} {{ Str::plural('refund', $report['other']['refunds']) }} ({{ Money::format($report['other']['refund_cents']) }}),@endif
                    in this Stripe account were made by another copy of Local Goods (for example local development sharing the sandbox), or before objects were tagged, and this database has no record of them. They are left out of every total above.
                </p>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-muted">
                    @foreach ($report['other']['lines'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        <form method="POST" action="{{ route('admin.reconciliation.retry') }}" data-busy="Retrying with Stripe…">
            @csrf
            <x-button type="submit">Retry owed transfers</x-button>
            <p class="mt-2 text-sm text-muted">Sends Stripe-funded amounts still owed to onboarded merchants. Simulated amounts are never sent.</p>
        </form>
    @endif

    <x-card as="section" id="operating-account" aria-labelledby="operating-account-heading" class="scroll-mt-24">
        <h2 id="operating-account-heading" class="text-xl font-semibold">Local Goods Operating on Stripe</h2>
        <p class="mt-2 text-muted">
            {{ \App\Models\OperatingAccount::LABEL }} It is separate from the customer funds account (the Stripe platform balance) and receives only money
            Local Goods has earned, moved by a recorded sweep. It has no bank account, so Stripe never pays it out: payouts are off on purpose.
        </p>
        <p class="mt-3" data-operating-account="{{ $operatingAccount?->stripe_account_id ? ($operatingAccount->transfers_active ? 'ready' : 'waiting') : 'none' }}">
            @if ($operatingAccount?->stripe_account_id)
                <x-badge :tone="$operatingAccount->transfers_active ? 'ok' : 'hold'">{{ $operatingAccount->transfers_active ? 'Set up, can receive sweeps' : 'Set up, Stripe is turning on transfers' }}</x-badge>
                <code class="wrap-anywhere-safe mt-1 block font-mono text-xs text-muted">{{ $operatingAccount->stripe_account_id }}</code>
            @else
                <x-badge tone="neutral">Not set up yet</x-badge>
            @endif
        </p>
        @if (($operating = $report['operating'] ?? null) && $operating['set_up'])
            <dl class="mt-4 grid gap-3 sm:grid-cols-3" data-operating-check="{{ $operating['matches'] === null ? 'unknown' : ($operating['matches'] ? 'yes' : 'no') }}">
                <div><dt class="text-sm text-muted">Sweeps sent to it (ledger)</dt><dd class="tabular font-semibold">{{ Money::format($operating['expected_cents']) }}</dd></div>
                <div><dt class="text-sm text-muted">Its balance in Stripe</dt><dd class="tabular font-semibold">{{ $operating['balance_cents'] === null ? 'Couldn\'t read it' : Money::format($operating['balance_cents']) }}</dd></div>
                <div><dt class="text-sm text-muted">Match?</dt><dd>
                    @if ($operating['matches'] === null)
                        <x-badge tone="neutral">Not checked</x-badge>
                    @elseif ($operating['matches'])
                        <x-badge tone="ok">Matches</x-badge>
                    @else
                        <x-badge tone="hold">Doesn't match</x-badge>
                    @endif
                </dd></div>
            </dl>
        @endif
        @if ($report['active'] && ! $operatingAccount?->transfers_active)
            <form method="POST" action="{{ route('admin.stripe.operating-account') }}" data-busy="Setting up Local Goods Operating…" class="mt-4">
                @csrf
                <x-button type="submit">{{ $operatingAccount?->stripe_account_id ? 'Check Local Goods Operating again' : 'Create the Local Goods Operating account (test mode)' }}</x-button>
            </form>
        @elseif (! $report['active'])
            <p class="mt-3 text-sm text-muted">Stripe test mode is off on this server, so there is no operating account to set up. Simulated sweeps are recorded in the ledger only.</p>
        @endif
    </x-card>

    <x-card as="section" id="demo-merchants" aria-labelledby="demo-merchants-heading" class="scroll-mt-24">
        <h2 id="demo-merchants-heading" class="text-xl font-semibold">Demo merchants on Stripe</h2>
        <p class="mt-2 text-muted">
            The fictional demo merchants with no login can't onboard themselves, so the platform can set up a Stripe test account for each:
            no Stripe dashboard, filled in by Local Goods with Stripe's test values, labelled "{{ \App\Models\Merchant::PLATFORM_SETUP_LABEL }}".
            Then a cleared Stripe-funded redemption at any of them sends a test transfer. Real merchants always onboard themselves through Stripe's Express pages,
            and so does any merchant with a login. Real DC businesses never get a Stripe account.
        </p>
        <x-rtable :card="false" class="mt-4" label="Demo merchants and their Stripe status">
            <table data-rtable="stack" class="w-full text-left text-sm">
                <caption class="sr-only">Each fictional demo merchant, whether it has a login, and its Stripe status</caption>
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-4 font-semibold">Merchant</th>
                        <th scope="col" class="py-2 pr-4 font-semibold">Login</th>
                        <th scope="col" class="py-2 font-semibold">Stripe</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($demoMerchants as $merchant)
                        <tr class="border-b border-line/60" data-demo-merchant="{{ $merchant->stripeOnboardingStatus() }}">
                            <td data-primary class="py-2 pr-4">{{ $merchant->name }}</td>
                            <td data-label="Login" class="py-2 pr-4">{{ $merchant->user_id ? 'Yes, onboards itself' : 'No' }}</td>
                            <td data-label="Stripe" class="py-2">{{ $merchant->stripeOnboardingLabel() }}@if ($merchant->stripe_account_id)<code class="wrap-anywhere-safe block font-mono text-xs text-muted">{{ $merchant->stripe_account_id }}</code>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-rtable>
        @if ($report['active'])
            @if ($demoProgress['done'] < $demoProgress['total'])
                {{-- One merchant per press, so no request waits long on Stripe. With JavaScript the page presses again by itself. --}}
                <form method="POST" action="{{ route('admin.stripe.demo-merchants') }}" data-busy="Connecting the next demo merchant…" class="mt-4"
                      @if ($continueRound !== null) data-auto-submit @endif>
                    @csrf
                    <input type="hidden" name="round" value="{{ $continueRound ?? 0 }}">
                    <x-button type="submit">{{ $demoProgress['done'] === 0 ? 'Connect demo merchants to Stripe (test mode)' : 'Continue connecting ('.$demoProgress['done'].' of '.$demoProgress['total'].' done)' }}</x-button>
                    <p class="mt-2 text-sm text-muted" data-demo-progress>
                        {{ $demoProgress['done'] }} of {{ $demoProgress['total'] }} demo merchants with no login are connected.
                        Each press connects one; the page continues by itself, or press again to continue. Pressing again never creates a second account.
                    </p>
                </form>
            @else
                <p class="mt-4 text-sm text-muted" data-demo-progress>All {{ $demoProgress['total'] }} demo merchants with no login are connected.</p>
            @endif
        @endif
    </x-card>

    <x-card as="section" aria-labelledby="simulated-heading">
        <h2 id="simulated-heading" class="flex flex-wrap items-center gap-2 text-xl font-semibold">Not part of this check <x-money-mode mode="simulated" /></h2>
        <p class="mt-2 text-muted">
            {{ $report['simulated']['purchases'] }} simulated {{ Str::plural('purchase', $report['simulated']['purchases']) }} ({{ Money::format($report['simulated']['purchase_cents']) }})
            and {{ $report['simulated']['payouts'] }} simulated {{ Str::plural('payout', $report['simulated']['payouts']) }} ({{ Money::format($report['simulated']['payout_cents']) }})@if ($report['simulated']['refunds'] > 0), plus {{ $report['simulated']['refunds'] }} simulated {{ Str::plural('correction', $report['simulated']['refunds']) }} ({{ Money::format($report['simulated']['refund_cents']) }})@endif:
            seeded demo history and anything paid while Stripe was off. They are in the ledger with no money behind them.
        </p>
    </x-card>
</div>
</x-app-shell>
