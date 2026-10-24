@php
    use App\Support\Money;

    $month = $impact['this_month'];
    $all = $impact['all_time'];
    $monthName = $impact['month']->format('F');
@endphp

<x-app-shell title="Impact" active="impact"
             :context="($bid?->name ?? 'Golden Triangle').' BID · '.($bid?->city ?? 'Washington, DC')">
<div class="space-y-10">
    <header class="max-w-3xl">
        <x-eyebrow class="text-accent-ink">{{ $bid?->name ?? 'Golden Triangle' }} BID · Impact</x-eyebrow>
        <h1 class="mt-1 text-4xl font-semibold text-primary sm:text-5xl">What the program did for the district</h1>
        <p class="mt-3 text-lg text-muted">
            Dollars spent at independent merchants, and who took part. Every number is read from the credit ledger and uses
            the same definitions as the employer dashboards, so the two always agree. Employees are counted, never named.
        </p>
        <p class="mt-3 inline-block rounded-full border-2 border-dashed border-muted px-3 py-1 text-sm font-semibold text-muted">
            Demo data: all people and transactions are fictional.
        </p>
    </header>

    @if (! $impact['has_activity'])
        <x-card as="section" aria-labelledby="empty-heading" class="border-2 border-dashed border-line ring-0! text-center" padding="px-6 py-12">
            <h2 id="empty-heading" class="text-2xl font-semibold text-primary">No cleared redemptions yet</h2>
            <p class="mx-auto mt-2 max-w-md text-muted">
                Once employees spend their credits and the redemptions clear, the dollars spent, merchants reached and
                weekly trend appear here.
                @if ($impact['held_count'] > 0)
                    {{ Money::format($impact['held_cents']) }} is held for review and not counted yet.
                @endif
            </p>
            <p class="mt-4 text-sm text-muted">
                {{ $impact['employers_total'] }} {{ Str::plural('employer', $impact['employers_total']) }},
                {{ $impact['enrolled'] }} enrolled {{ Str::plural('employee', $impact['enrolled']) }} and
                {{ $impact['merchants_total'] }} participating {{ Str::plural('merchant', $impact['merchants_total']) }} so far.
            </p>
        </x-card>
    @else
        <section aria-labelledby="totals-heading" class="space-y-4">
            <h2 id="totals-heading" class="text-2xl font-semibold text-primary">{{ $monthName }} so far, and since launch</h2>

            @php
                $tiles = [
                    [
                        'label' => 'Spent at independent merchants',
                        'value' => Money::format($month['spent_cents']),
                        'detail' => Money::format($all['spent_cents']).' since launch'.($impact['held_count'] > 0 ? ' · '.Money::format($impact['held_cents']).' more waiting for review' : ''),
                        'how' => 'The total of cleared redemptions: each is one "merchant earned" entry in the ledger, written only when a redemption clears. Held and denied redemptions are left out. A redemption counts in the month it happened, in Washington, DC time, even if a person cleared it later. The same total as "Spent locally" on the employer dashboards, added up.',
                        'night' => true,
                    ],
                    [
                        'label' => 'Merchants reached',
                        'value' => $month['merchants'].' of '.$impact['merchants_total'],
                        'unit' => 'participating',
                        'detail' => $all['merchants'].' since launch',
                        'how' => 'Different merchants with at least one cleared redemption, out of every merchant taking part in the BID. All are fictional demo businesses, never the real candidates under eligibility review.',
                    ],
                    [
                        'label' => 'Employees participating',
                        'value' => number_format($month['employees']),
                        'unit' => 'of '.$impact['enrolled'].' enrolled',
                        'detail' => $all['employees'].' since launch',
                        'how' => 'Employees with at least one cleared redemption, counted only: this page never shows who. This month\'s number is "Employees who got out" from each employer dashboard, added up.',
                    ],
                    [
                        'label' => 'Employers participating',
                        'value' => $month['employers'].' of '.$impact['employers_total'],
                        'detail' => $all['employers'].' since launch',
                        'how' => 'Employers with at least one employee who had a cleared redemption, out of every employer in the program.',
                    ],
                ];
            @endphp
            <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($tiles as $tile)
                    @php $night = $tile['night'] ?? false; @endphp
                    <x-card :tone="$night ? 'night' : 'surface'" class="flex flex-col">
                        <dt class="text-sm font-semibold {{ $night ? 'text-night-muted' : 'text-muted' }}">{{ $tile['label'] }}</dt>
                        <dd class="mt-2 {{ $night ? 'text-night-accent' : 'text-primary' }}">
                            <span class="tabular font-display text-4xl font-semibold">{{ $tile['value'] }}</span>
                            @isset($tile['unit'])<span class="font-semibold"> {{ $tile['unit'] }}</span>@endisset
                        </dd>
                        <dd class="mt-1 text-sm {{ $night ? 'text-night-muted' : 'text-muted' }}">{{ $monthName }} so far · {{ $tile['detail'] }}</dd>
                        <dd class="mt-3 border-t {{ $night ? 'border-night-line/40' : 'border-line/70' }} pt-2 text-sm">
                            <details>
                                <summary class="cursor-pointer font-semibold {{ $night ? 'text-on-night' : 'text-primary' }}">How we count this</summary>
                                <p class="mt-1 {{ $night ? 'text-night-muted' : 'text-muted' }}">{{ $tile['how'] }}</p>
                            </details>
                        </dd>
                    </x-card>
                @endforeach
            </dl>
        </section>

        <x-card as="section" aria-labelledby="trend-heading">
            @php
                $weeks = $impact['weeks'];
                $max = max(1, max(array_column($weeks, 'cents')));
            @endphp
            <h2 id="trend-heading" class="text-xl font-semibold">Spent at independent merchants per week, last {{ count($weeks) }} weeks</h2>
            <p class="mt-1 text-sm text-muted">Monday to Sunday. The last bar is this week so far.</p>

            @if (array_sum(array_column($weeks, 'cents')) === 0)
                <p class="mt-6 rounded-xl bg-band p-4 text-muted">No cleared redemptions in the last {{ count($weeks) }} weeks.</p>
            @else
                {{-- Bars are hidden from screen readers: the sentence under them sums them up and
                     "Show as a table" has every week's numbers. Hover a bar for its week. --}}
                <div class="mt-6 flex h-44 items-end gap-1.5 border-b border-muted/40 sm:gap-3" aria-hidden="true">
                    @foreach ($weeks as $week)
                        <div class="group relative flex h-full flex-1 items-end justify-center">
                            <div class="w-full max-w-12 rounded-t-[4px] bg-primary transition-colors group-hover:bg-primary-hover"
                                 style="height: {{ max(2, round(100 * $week['cents'] / $max)) }}%"></div>
                            <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 hidden w-max -translate-x-1/2 rounded-lg bg-night px-2.5 py-1.5 text-xs text-on-night shadow group-hover:block">
                                <span class="font-semibold">Week of {{ $week['start']->format('M j') }}</span><br>
                                {{ Money::format($week['cents']) }} · {{ $week['visits'] }} {{ Str::plural('visit', $week['visits']) }}
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1.5 flex gap-1.5 text-center text-xs text-muted sm:gap-3" aria-hidden="true">
                    @foreach ($weeks as $i => $week)
                        <span class="flex-1">{{ $i % 2 === count($weeks) % 2 ? '' : $week['start']->format('M j') }}</span>
                    @endforeach
                </div>
                @php $peak = collect($weeks)->sortByDesc('cents')->first(); @endphp
                <p class="mt-3 text-sm">
                    <span class="font-semibold">{{ Money::format($weeks[count($weeks) - 1]['cents']) }}</span> so far this week,
                    out of a peak of <span class="font-semibold">{{ Money::format($peak['cents']) }}</span> (week of {{ $peak['start']->format('M j') }}).
                </p>
            @endif

            <details class="mt-3 text-sm">
                <summary class="cursor-pointer font-semibold text-primary">Show as a table</summary>
                <table data-rtable="fit" class="mt-2 w-full text-left">
                    <caption class="sr-only">Cleared redemptions and dollars spent at independent merchants per week</caption>
                    <thead class="text-muted"><tr><th scope="col" class="py-1 font-semibold">Week of</th><th scope="col" class="py-1 text-right font-semibold">Visits</th><th scope="col" class="py-1 text-right font-semibold">Spent</th></tr></thead>
                    <tbody class="tabular divide-y divide-line/70">
                        @foreach ($weeks as $week)
                            <tr><th scope="row" class="py-1 font-normal">{{ $week['start']->format('M j') }}</th><td class="py-1 text-right">{{ $week['visits'] }}</td><td class="py-1 text-right">{{ Money::format($week['cents']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
            <details class="mt-2 text-sm">
                <summary class="cursor-pointer font-semibold text-primary">How we count this</summary>
                <p class="mt-1 text-muted">Cleared redemptions by the week they happened, in Washington, DC time: the employer dashboards' weekly trend, added up across employers.</p>
            </details>
        </x-card>

        <section aria-labelledby="employers-heading">
            <h2 id="employers-heading" class="text-2xl font-semibold text-primary">Employer engagement, {{ $monthName }} so far</h2>
            <p class="mt-1 text-sm text-muted">Each row is the employer's own HR dashboard, unchanged, so the numbers match what HR sees.</p>

            <x-rtable class="mt-3" label="Employer engagement table">
                <table data-rtable="stack" class="w-full min-w-2xl text-left">
                    <caption class="sr-only">Each employer's engagement this month, as shown on its HR dashboard</caption>
                    <thead class="border-b border-line/70 text-sm text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold">Employer</th>
                            <th scope="col" class="px-5 py-3 text-right font-semibold">Employees who got out</th>
                            <th scope="col" class="px-5 py-3 text-right font-semibold">Visits out of the office</th>
                            <th scope="col" class="px-5 py-3 text-right font-semibold">Local businesses visited</th>
                            <th scope="col" class="px-5 py-3 text-right font-semibold">First-time visits</th>
                            <th scope="col" class="px-5 py-3 text-right font-semibold">Spent locally</th>
                        </tr>
                    </thead>
                    <tbody class="tabular divide-y divide-line/70">
                        @foreach ($impact['employers'] as ['employer' => $employer, 'metrics' => $m])
                            <tr>
                                <th scope="row" data-primary class="px-5 py-3 font-semibold">{{ $employer->name }}</th>
                                <td data-label="Employees who got out" class="px-5 py-3 text-right">{{ $m['redeemers'] }} of {{ $m['enrolled'] }} <span class="text-sm text-muted">({{ $m['participation_pct'] }}%)</span></td>
                                <td data-label="Visits out of the office" class="px-5 py-3 text-right">{{ $m['visits'] }}</td>
                                <td data-label="Local businesses visited" class="px-5 py-3 text-right">{{ $m['businesses'] }} of {{ $m['participating'] }}</td>
                                <td data-label="First-time visits" class="px-5 py-3 text-right">{{ $m['first_visits'] }}</td>
                                <td data-label="Spent locally" class="px-5 py-3 text-right">{{ Money::format($m['spent_cents']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
            <details class="mt-2 text-sm">
                <summary class="cursor-pointer font-semibold text-primary">How we count this</summary>
                <p class="mt-1 text-muted">
                    The same definitions as the employer dashboard: employees with at least one cleared redemption this month, out of those enrolled;
                    each cleared redemption is one visit; different businesses visited, out of every participating business; a first-time visit is
                    someone going to a business they had never redeemed at before; spent locally is the total of cleared redemptions.
                </p>
            </details>
        </section>

        @if ($impact['recirculation_cents'] !== null)
            <aside aria-labelledby="estimate-heading" class="rounded-3xl border-2 border-dashed border-muted bg-band p-6 sm:p-8" data-recirculation-estimate>
                <h2 id="estimate-heading" class="text-2xl font-semibold text-primary">How much of this spending might stay local</h2>
                <div class="mt-3">
                    @include('partials.recirculation-evidence')
                </div>
                <p class="mt-3 max-w-3xl text-lg">
                    If the national study's rate held here, about
                    <strong class="tabular font-semibold">{{ Money::format($impact['recirculation_cents']) }}</strong>
                    of this spending would recirculate locally. We multiply the {{ Money::format($all['spent_cents']) }} spent since launch by
                    {{ round(100 * $source['independent_rate'], 1) }}%; the real share here could be higher or lower.
                </p>
            </aside>
        @endif
    @endif
</div>
</x-app-shell>
