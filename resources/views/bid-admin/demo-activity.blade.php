@php
    use App\Models\DemoActivityAction;
    use App\Support\Money;

    $kind = fn (DemoActivityAction $a) => match ($a->kind) {
        DemoActivityAction::KIND_PURCHASE => 'Bought credits',
        DemoActivityAction::KIND_ALLOWANCE => 'Monthly allowances',
        DemoActivityAction::KIND_RECOGNITION => 'Recognition award',
        DemoActivityAction::KIND_REFUND_REQUEST => 'Refund request',
        default => $a->channel === 'in_person' ? 'Paid in person' : 'Ordered to go',
    };
    $tone = fn (string $status) => match ($status) {
        DemoActivityAction::STATUS_DONE => 'ok',
        DemoActivityAction::STATUS_FAILED => 'danger',
        DemoActivityAction::STATUS_SKIPPED => 'neutral',
        default => 'hold',
    };
    $statusLabel = fn (string $status) => match ($status) {
        DemoActivityAction::STATUS_DONE => 'Done',
        DemoActivityAction::STATUS_FAILED => 'Failed',
        DemoActivityAction::STATUS_SKIPPED => 'Skipped',
        default => 'Running',
    };
    $time = fn ($at) => $at?->setTimezone('America/New_York')->format('M j, g:i A');
@endphp

<x-app-shell title="Demo activity" active="demo-activity" context="Golden Triangle BID · Washington, DC">
<div class="space-y-10">
    <header class="max-w-3xl">
        <x-eyebrow class="text-accent-ink">Generated demo activity</x-eyebrow>
        <h1 class="mt-1 text-title font-semibold text-primary">Fictional people, real Stripe test-mode transactions</h1>
        <p class="mt-3 text-lg text-muted">
            Between now and judging, a scheduled generator makes a small, realistic slice of activity each weekday, busiest at lunch:
            buying credits, allowances, orders to go and payments in person at the demo merchants, and the occasional refund request.
            It goes through exactly the same steps as a person would: Redemption Fraud Detection scores every payment, Stripe test mode moves the money,
            and nothing is backdated. The people and merchants are fictional.
        </p>
        <p class="mt-3 text-muted">
            It never decides anything. A payment the AI holds, and every refund request, waits for a person, who decides it and writes why.
            Everything it made is marked <x-demo-activity-badge /> wherever it appears. The seeded history before it started stays labelled simulated.
        </p>
        <p id="demo-activity-read-only" class="mt-4 rounded-2xl bg-band p-4 text-sm"><span class="font-semibold">Read-only.</span>
            It is started and stopped only by a setting on the server (<code>DEMO_ACTIVITY_ENABLED</code>), never from this page.</p>
    </header>

    <section aria-labelledby="state-heading" class="space-y-4">
        <h2 id="state-heading" class="text-2xl font-semibold text-primary">Right now</h2>
        <dl class="grid gap-3 sm:grid-cols-3">
            <x-card padding="p-4 sm:p-5">
                <dt class="text-sm font-semibold text-muted">Generator</dt>
                <dd class="mt-1 text-xl font-semibold" data-demo-activity-state="{{ $s['state'] }}">
                    {{ match ($s['state']) { 'on' => 'On', 'ended' => 'Ended', default => 'Off' } }}
                </dd>
                <dd class="mt-1 text-sm text-muted">{{ $s['refusal'] ?? 'Running on its schedule.' }}</dd>
            </x-card>
            <x-card padding="p-4 sm:p-5">
                <dt class="text-sm font-semibold text-muted">Schedule</dt>
                <dd class="mt-1 text-xl font-semibold">Weekdays, {{ $s['hours']['from'] }}–{{ $s['hours']['until'] }} DC time</dd>
                <dd class="mt-1 text-sm text-muted">Every five minutes. Stops by itself after {{ $s['ends_on']->format('F j, Y') }}.</dd>
            </x-card>
            <x-card padding="p-4 sm:p-5">
                <dt class="text-sm font-semibold text-muted">Stripe</dt>
                <dd class="mt-1 text-xl font-semibold">{{ $s['test_mode'] ? 'Test mode' : 'Not connected' }}</dd>
                <dd class="mt-1 text-sm text-muted">It refuses to run unless Stripe is in test mode.</dd>
            </x-card>
        </dl>

        <x-card padding="p-4 sm:p-5">
            <h3 class="font-semibold">Today, {{ $s['today']['date']->format('l, F j') }}</h3>
            @if ($s['today']['planned'] === 0)
                <p class="mt-1 text-muted">Nothing planned: it runs on weekdays only.</p>
            @else
                <p class="mt-1">
                    {{ $s['today']['done'] }} done, {{ $s['today']['skipped'] }} skipped and {{ $s['today']['failed'] }} failed, of {{ $s['today']['planned'] }} planned.
                    @if ($s['today']['next'] && ! $s['today']['halted']) Next at {{ $s['today']['next'] }}. @endif
                </p>
                @if ($s['today']['halted'])
                    <p class="mt-2 rounded-xl bg-hold-bg p-3 text-sm text-hold"><span class="font-semibold">Stopped for today:</span> {{ $s['today']['halted'] }}</p>
                @endif
            @endif
            @if ($rec = $s['today']['reconciliation'])
                <p class="mt-2 text-sm text-muted">Stripe reconciliation, checked {{ $time(\Illuminate\Support\Carbon::parse($rec['at'])) }}: {{ $rec['matches'] ? 'everything matches.' : 'did not match.' }}</p>
            @endif
        </x-card>

        @include('partials.waiting-for-a-person')
    </section>

    <section aria-labelledby="holds-heading" class="max-w-3xl space-y-2">
        <h2 id="holds-heading" class="text-2xl font-semibold text-primary">How often the AI held a generated payment</h2>
        <p>
            Held {{ $s['holds']['held'] }} of {{ $s['holds']['redemptions'] }} generated payments.
            About every other day one payment is planned as unusual on purpose (a round amount far above a café's usual, at an odd hour, using most of someone's balance),
            so the AI has something real to question. The AI is never told: it sees the same anonymous signals as for any payment.
            It held {{ $s['holds']['unusual_held'] }} of the {{ $s['holds']['unusual'] }} planned as unusual.
        </p>
    </section>

    <section aria-labelledby="days-heading" class="space-y-3">
        <h2 id="days-heading" class="text-2xl font-semibold text-primary">By day</h2>
        @if ($s['days']->isEmpty())
            <x-card><p class="text-muted">Nothing yet. Once it is switched on, each weekday it runs shows here.</p></x-card>
        @else
            <x-rtable label="Demo activity by day">
                <table data-rtable="stack" class="w-full text-left">
                    <caption class="sr-only">Demo activity by day</caption>
                    <thead class="border-b border-line text-sm text-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Day</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Payments</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Spent</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Credits bought</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Held by the AI</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Stripe transfers</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Failed</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Reconciliation</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($s['days'] as $d)
                            <tr>
                                <td data-primary class="px-4 py-3 font-semibold">
                                    {{ $d['date']->format('D, M j') }}
                                    @if ($d['halted']) <span class="block text-sm font-normal text-hold">Stopped: {{ $d['halted'] }}</span> @endif
                                </td>
                                <td data-label="Payments" class="tabular px-4 py-3">{{ $d['redemptions'] }} ({{ $d['in_person'] }} in person)</td>
                                <td data-label="Spent" class="tabular px-4 py-3">{{ Money::format($d['spend_cents']) }}</td>
                                <td data-label="Credits bought" class="tabular px-4 py-3">{{ $d['purchase_cents'] > 0 ? Money::format($d['purchase_cents']) : '—' }}</td>
                                <td data-label="Held by the AI" class="tabular px-4 py-3">{{ $d['held'] }}</td>
                                <td data-label="Stripe transfers" class="tabular px-4 py-3">{{ $d['transfers'] }}</td>
                                <td data-label="Failed" class="tabular px-4 py-3">{{ $d['failed'] }}</td>
                                <td data-label="Reconciliation" class="px-4 py-3">{{ $d['reconciliation'] === null ? 'Not checked' : ($d['reconciliation'] ? 'Matches' : 'Did not match') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
        @endif
    </section>

    <section aria-labelledby="actions-heading" class="space-y-3">
        <h2 id="actions-heading" class="text-2xl font-semibold text-primary">Latest actions</h2>
        @if ($s['actions']->isEmpty())
            <x-card><p class="text-muted">No actions yet.</p></x-card>
        @else
            <x-rtable label="Latest demo activity actions">
                <table data-rtable="stack" class="w-full text-left">
                    <caption class="sr-only">Latest demo activity actions</caption>
                    <thead class="border-b border-line text-sm text-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">When</th>
                            <th scope="col" class="px-4 py-3 font-semibold">What</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Where and who</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Amount</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Result</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($s['actions'] as $a)
                            <tr>
                                <td data-label="When" class="px-4 py-3 text-sm">{{ $time($a->ran_at) }}</td>
                                <td data-primary class="px-4 py-3 font-semibold">
                                    {{ $kind($a) }}
                                    @if ($a->unusual) <span class="block text-sm font-normal text-muted">Planned as unusual</span> @endif
                                </td>
                                <td data-label="Where and who" class="px-4 py-3 text-sm">
                                    {{ collect([$a->merchant?->name, $a->employee?->initials()])->filter()->implode(' · ') ?: '—' }}
                                </td>
                                <td data-label="Amount" class="tabular px-4 py-3">{{ $a->amount_cents > 0 ? Money::format($a->amount_cents) : '—' }}</td>
                                <td data-label="Result" class="px-4 py-3 text-sm">
                                    <x-badge :tone="$tone($a->status)">{{ $statusLabel($a->status) }}</x-badge>
                                    @if ($a->redemption?->isUnderReview()) <span class="block">Held: waiting for a person</span>
                                    @elseif ($a->redemption?->reviewed_at) <span class="block">Held, then decided by a person</span>
                                    @elseif ($a->redemption) <span class="block">Cleared by the AI</span>
                                    @endif
                                    @if ($a->correction) <span class="block">{{ $a->correction->statusLabel() }}</span> @endif
                                    @if ($a->status === DemoActivityAction::STATUS_SKIPPED && $a->note) <span class="block text-muted">{{ $a->note }}</span> @endif
                                    @if ($a->status === DemoActivityAction::STATUS_FAILED) <span class="block text-danger">Failed; details are in the server log.</span> @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
        @endif
    </section>
</div>
</x-app-shell>
