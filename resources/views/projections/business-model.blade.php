@php
    use App\Support\Projections\BusinessModel;
    use App\Support\Projections\Figures;

    $config = config('projections');
    $chosen = $config['scenarios'][$scenario];
    $caseName = strtolower($config['cases'][$case]);
    $breakEven = $projection->breakEvenMonth();
    $peak = $projection->peakCashNeeded();
    $units = $projection->unitEconomics();
    $year4 = $years[count($years) - 1];
    $search = $config['break_even_search_months'];
    $link = 'font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary';
    $docs = 'https://github.com/finnbutler1/local-goods/blob/main/docs/finance/MODEL.md';
    $monthText = fn (?int $m) => $m === null ? "not within {$search} months" : "month {$m} (year ".(int) ceil($m / 12).')';
    $sections = [
        ['Revenue', BusinessModel::REVENUE_LINES, 'revenue', 'Revenue'],
        ['Cost of revenue', BusinessModel::COST_OF_REVENUE_LINES, 'cost_of_revenue', 'Cost of revenue'],
        ['Operating expenses', BusinessModel::OPEX_LINES, 'opex', 'Operating expenses'],
    ];
    $th = 'px-4 py-2.5 text-right font-semibold';
    $td = 'px-4 py-2.5 text-right';
@endphp

<x-app-shell title="Business model" active="business-model" context="Projection, not results">
<div class="space-y-10">
    <header class="max-w-3xl">
        <x-eyebrow class="text-accent-ink">Business model</x-eyebrow>
        <h1 class="mt-1 text-title font-semibold text-primary">How Local Goods could pay for itself</h1>
        <p class="mt-3 text-lg text-muted">
            A projected P&amp;L for three ways Local Goods could grow, worked out month by month from
            <a href="#assumptions" class="{{ $link }}">stated assumptions</a>, each with a source or labelled an assumption to check.
            None of it is a result: Local Goods hasn't run a pilot yet.
        </p>
        <div class="mt-4">@include('projections.partials.label')</div>
    </header>

    <x-card as="section" aria-labelledby="choose-heading">
        <h2 id="choose-heading" class="sr-only">Choose a scenario and case</h2>
        @include('projections.partials.toggles', ['route' => 'business-model'])
        <p class="mt-4 text-sm">
            <span class="font-semibold">{{ $chosen['name'] }}:</span> {{ $chosen['note'] }}
            The {{ $caseName }} case uses the {{ $caseName }} value of each demand and pricing assumption; costs are the same in every case.
        </p>
    </x-card>

    <section aria-labelledby="headline-heading" class="space-y-4">
        <h2 id="headline-heading" class="text-2xl font-semibold text-primary">{{ $chosen['name'] }}, {{ $caseName }} case</h2>
        <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-card tone="night" class="flex flex-col">
                <dt class="text-sm font-semibold text-night-muted">Monthly break-even</dt>
                <dd class="mt-2 font-display text-3xl font-semibold text-night-accent">{{ $breakEven === null ? 'Not reached' : 'Month '.$breakEven }}</dd>
                <dd class="mt-1 text-sm text-night-muted">{{ $breakEven === null ? "Monthly EBITDA stays below 0 through month {$search}." : "Monthly EBITDA is above 0 from then on (checked to month {$search})." }}</dd>
            </x-card>
            <x-card class="flex flex-col">
                <dt class="text-sm font-semibold text-muted">Peak cash needed</dt>
                <dd class="tabular mt-2 font-display text-3xl font-semibold text-primary">{{ Figures::dollars($peak['amount']) }}</dd>
                <dd class="mt-1 text-sm text-muted">{{ $peak['still_growing'] ? "Still growing at month {$search}." : "Deepest point: month {$peak['month']}." }} Operating losses only, never customer funds.</dd>
            </x-card>
            <x-card class="flex flex-col">
                <dt class="text-sm font-semibold text-muted">Year 4 revenue</dt>
                <dd class="tabular mt-2 font-display text-3xl font-semibold text-primary">{{ Figures::dollars($year4['revenue']) }}</dd>
                <dd class="mt-1 text-sm text-muted">Gross margin {{ Figures::percent($year4['gross_margin'], 0) }}</dd>
            </x-card>
            <x-card class="flex flex-col">
                <dt class="text-sm font-semibold text-muted">Year 4 EBITDA</dt>
                <dd @class(['tabular mt-2 font-display text-3xl font-semibold', 'text-danger' => $year4['ebitda'] < 0, 'text-primary' => $year4['ebitda'] >= 0])>{{ Figures::dollars($year4['ebitda']) }}</dd>
                <dd class="mt-1 text-sm text-muted">{{ $year4['headcount'] }} people on payroll at the end of year 4</dd>
            </x-card>
        </dl>
        <p class="text-sm">
            <span class="font-semibold">Sensitivity:</span>
            @foreach ($sensitivity as $fee => $month)
                with an employer platform fee of {{ Figures::exact($fee) }} instead of {{ Figures::exact($model->assumptions($case)->value('employer_platform_fee')) }}, monthly break-even would be {{ $monthText($month) }}{{ $loop->last ? '.' : ';' }}
            @endforeach
        </p>
    </section>

    <x-card as="section" aria-labelledby="takes-heading" class="border-l-4 border-l-primary">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="takes-heading" class="text-2xl font-semibold text-primary">What it takes</h2>
            @include('projections.partials.label')
        </div>
        <p class="mt-2 max-w-3xl text-muted">
            One Golden Triangle BID on its own (scenario A), fully enrolled, in an ordinary month: what it needs for monthly EBITDA to reach 0,
            at the {{ $caseName }} case's fees and costs. Searched month by month, because payroll grows in steps.
        </p>
        <dl class="mt-4 grid gap-3 md:grid-cols-2">
            <div class="rounded-xl bg-band p-4">
                <dt class="text-sm font-semibold text-muted">At an employer platform fee of {{ Figures::exact($whatItTakes->currentFee) }}</dt>
                <dd class="tabular mt-1 text-2xl font-semibold text-primary">
                    {{ $whatItTakes->employees === null ? 'Not reachable' : Figures::count($whatItTakes->employees).' participating employees' }}
                </dd>
                @if ($whatItTakes->employees !== null)
                    <dd class="mt-1 text-sm">That is {{ Figures::count($whatItTakes->employers) }} employers at {{ Figures::count($whatItTakes->employeesPerEmployer) }} participating employees each. The {{ $caseName }} case assumes {{ Figures::count($whatItTakes->participation) }}.</dd>
                @endif
            </div>
            <div class="rounded-xl bg-band p-4">
                <dt class="text-sm font-semibold text-muted">At {{ Figures::count($whatItTakes->participation) }} participating employees</dt>
                <dd class="tabular mt-1 text-2xl font-semibold text-primary">
                    {{ $whatItTakes->breakEvenFee === null ? 'No fee up to $100 is enough' : 'An employer platform fee of '.Figures::exact($whatItTakes->breakEvenFee) }}
                </dd>
                <dd class="mt-1 text-sm">Per participating employee per month, against {{ Figures::exact($whatItTakes->currentFee) }} assumed. Benefit platforms are estimated at about $4 to $12.</dd>
            </div>
        </dl>
    </x-card>

    <x-card as="section" aria-labelledby="chart-heading">
        <h2 id="chart-heading" class="text-xl font-semibold">Revenue and EBITDA by year</h2>
        <p class="mt-1 text-sm text-muted">Bars below zero are losses. The P&amp;L table below has every number.</p>
        <div class="grid gap-8 md:grid-cols-2">
            <div>
                <h3 class="mt-4 text-sm font-semibold text-muted">Revenue</h3>
                @include('projections.partials.bars', ['bars' => array_map(fn ($y) => ['label' => 'Year '.$y['year'], 'value' => $y['revenue'], 'text' => Figures::dollars($y['revenue'])], $years)])
            </div>
            <div>
                <h3 class="mt-4 text-sm font-semibold text-muted">EBITDA</h3>
                @include('projections.partials.bars', ['bars' => array_map(fn ($y) => ['label' => 'Year '.$y['year'], 'value' => $y['ebitda'], 'text' => Figures::dollars($y['ebitda'])], $years)])
            </div>
        </div>
    </x-card>

    <section aria-labelledby="pnl-heading">
        <h2 id="pnl-heading" class="text-2xl font-semibold text-primary">Projected P&amp;L</h2>
        <p class="mt-1 max-w-3xl text-sm text-muted">
            {{ $chosen['name'] }}, {{ $caseName }} case, by year. Revenue is only Local Goods' own fees. The credits employers buy pass through to
            merchants and are customer funds, never revenue; credits left unspent stay the employer's money.
        </p>
        <x-rtable class="mt-3" label="Projected P&L table">
            <table data-rtable="stack" class="w-full min-w-2xl text-left text-sm">
                <caption class="sr-only">Projected profit and loss by year</caption>
                <thead class="border-b border-line/70 text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-semibold">Line</th>
                        @foreach ($years as $y)<th scope="col" class="{{ $th }}">Year {{ $y['year'] }}</th>@endforeach
                    </tr>
                </thead>
                <tbody class="tabular divide-y divide-line/70">
                    @foreach ($sections as [$heading, $lines, $total, $totalLabel])
                        @foreach ($lines as $key => $label)
                            <tr>
                                <th scope="row" data-primary class="px-4 py-2.5 font-normal">{{ $label }}</th>
                                @foreach ($years as $y)<td data-label="Year {{ $y['year'] }}" class="{{ $td }}">{{ Figures::dollars($y[$key]) }}</td>@endforeach
                            </tr>
                        @endforeach
                        <tr class="bg-band">
                            <th scope="row" data-primary class="px-4 py-2.5 font-semibold">{{ $totalLabel }}</th>
                            @foreach ($years as $y)<td data-label="Year {{ $y['year'] }}" class="{{ $td }} font-semibold">{{ Figures::dollars($y[$total]) }}</td>@endforeach
                        </tr>
                        @if ($total === 'cost_of_revenue')
                            <tr class="bg-band">
                                <th scope="row" data-primary class="px-4 py-2.5 font-semibold">Gross profit (margin)</th>
                                @foreach ($years as $y)<td data-label="Year {{ $y['year'] }}" class="{{ $td }} font-semibold">{{ Figures::dollars($y['gross_profit']) }} <span class="font-normal text-muted">({{ Figures::percent($y['gross_margin'], 0) }})</span></td>@endforeach
                            </tr>
                        @endif
                    @endforeach
                    <tr class="bg-primary-tint">
                        <th scope="row" data-primary class="px-4 py-2.5 font-semibold">EBITDA</th>
                        @foreach ($years as $y)<td data-label="Year {{ $y['year'] }}" class="{{ $td }} font-semibold">{{ Figures::dollars($y['ebitda']) }}</td>@endforeach
                    </tr>
                    <tr>
                        <th scope="row" data-primary class="px-4 py-2.5 font-normal">People on payroll, year end</th>
                        @foreach ($years as $y)<td data-label="Year {{ $y['year'] }}" class="{{ $td }}">{{ $y['headcount'] }}</td>@endforeach
                    </tr>
                </tbody>
            </table>
        </x-rtable>
    </section>

    <section aria-labelledby="compare-heading">
        <h2 id="compare-heading" class="text-2xl font-semibold text-primary">The three scenarios side by side</h2>
        <p class="mt-1 text-sm text-muted">All three in the {{ $caseName }} case.</p>
        <x-rtable class="mt-3" label="Scenario comparison table">
            <table data-rtable="stack" class="w-full min-w-3xl text-left text-sm">
                <caption class="sr-only">Revenue and EBITDA by year, break-even and peak cash for each scenario</caption>
                <thead class="border-b border-line/70 text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-semibold">Scenario</th>
                        @foreach ([1, 2, 3, 4] as $n)<th scope="col" class="{{ $th }}">Year {{ $n }} revenue / EBITDA</th>@endforeach
                        <th scope="col" class="{{ $th }}">Monthly break-even</th>
                        <th scope="col" class="{{ $th }}">Peak cash needed</th>
                    </tr>
                </thead>
                <tbody class="tabular divide-y divide-line/70">
                    @foreach ($comparison as $key => $p)
                        <tr>
                            <th scope="row" data-primary class="px-4 py-2.5 font-semibold">{{ $config['scenarios'][$key]['name'] }}</th>
                            @foreach ($p->years() as $y)<td data-label="Year {{ $y['year'] }} revenue / EBITDA" class="{{ $td }}">{{ Figures::dollars($y['revenue']) }} / {{ Figures::dollars($y['ebitda']) }}</td>@endforeach
                            <td data-label="Monthly break-even" class="{{ $td }}">{{ $p->breakEvenMonth() === null ? 'Not reached' : 'Month '.$p->breakEvenMonth() }}</td>
                            <td data-label="Peak cash needed" class="{{ $td }}">{{ Figures::dollars($p->peakCashNeeded()['amount']) }}{{ $p->peakCashNeeded()['still_growing'] ? ', still growing' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-rtable>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-card as="section" aria-labelledby="units-heading">
            <h2 id="units-heading" class="text-xl font-semibold">Unit economics, year 4</h2>
            <dl class="mt-3 divide-y divide-line/70 text-sm">
                @foreach ([
                    'Revenue per participating employee per month' => Figures::exact($units['revenue_per_employee_month']),
                    'Cost of revenue per participating employee per month' => Figures::exact($units['cost_of_revenue_per_employee_month']),
                    'Contribution per participating employee per month' => Figures::exact($units['contribution_per_employee_month']),
                    'Revenue per BID per year' => Figures::dollars($units['revenue_per_bid_year']),
                    'Gross profit per BID per year' => Figures::dollars($units['gross_profit_per_bid_year']),
                ] as $label => $value)
                    <div class="flex justify-between gap-4 py-2"><dt>{{ $label }}</dt><dd class="tabular font-semibold">{{ $value }}</dd></div>
                @endforeach
            </dl>
            <p class="mt-2 text-sm text-muted">Year 4 totals divided by employee-months and by the average number of BIDs running that year. BIDs still enrolling employers bring this down.</p>
        </x-card>

        <x-card as="section" aria-labelledby="funds-heading">
            <h2 id="funds-heading" class="text-xl font-semibold">Customer funds held: a promise to pay</h2>
            <p class="mt-1 text-sm text-muted">
                Money that belongs to employers, employees and merchants, held by Stripe. Never revenue and never operating cash.
                Unspent credits stay the employer's money: breakage is excluded from revenue by policy.
            </p>
            <x-rtable class="mt-3" label="Customer funds table" :card="false">
                <table data-rtable="stack" class="w-full text-left text-sm">
                    <caption class="sr-only">Customer funds held at each year's end</caption>
                    <thead class="border-b border-line/70 text-muted">
                        <tr>
                            <th scope="col" class="py-2 pr-3 font-semibold">Year end</th>
                            <th scope="col" class="py-2 pr-3 text-right font-semibold">Unspent employee balances</th>
                            <th scope="col" class="py-2 pr-3 text-right font-semibold">Owed to merchants</th>
                            <th scope="col" class="py-2 text-right font-semibold">Returned to employers in the year</th>
                        </tr>
                    </thead>
                    <tbody class="tabular divide-y divide-line/70">
                        @foreach ($projection->customerFunds() as $f)
                            <tr>
                                <th scope="row" data-primary class="py-2 pr-3 font-semibold">Year {{ $f['year'] }}</th>
                                <td data-label="Unspent employee balances" class="py-2 pr-3 text-right">{{ Figures::dollars($f['employee_balances']) }}</td>
                                <td data-label="Owed to merchants" class="py-2 pr-3 text-right">{{ Figures::dollars($f['owed_to_merchants']) }}</td>
                                <td data-label="Returned to employers in the year" class="py-2 text-right">{{ Figures::dollars($f['returned_to_employers']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
        </x-card>
    </div>

    <section aria-labelledby="district-heading">
        <h2 id="district-heading" class="text-2xl font-semibold text-primary">District impact</h2>
        <p class="mt-1 text-sm text-muted">The same numbers as the public <a href="{{ route('projection', ['scenario' => $scenario, 'case' => $case]) }}" class="{{ $link }}">impact projection</a>.</p>
        @include('projections.partials.district', ['district' => $projection->districtImpact()])
        <div class="mt-6 rounded-3xl border-2 border-dashed border-muted bg-band p-6">
            @include('partials.recirculation-evidence')
            <p class="mt-3 text-sm">
                If the study's rates held here, about {{ Figures::dollars($projection->districtImpact()[3]['recirculated_if_independent']) }} of year 4's projected
                spending would recirculate locally. Both are estimates on top of a projection.
            </p>
        </div>
    </section>

    <section id="assumptions" aria-labelledby="assumptions-heading" class="scroll-mt-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="assumptions-heading" class="text-2xl font-semibold text-primary">Assumptions and sources</h2>
            @include('projections.partials.label')
        </div>
        <p class="mt-1 max-w-3xl text-muted">
            Every number above comes from these, and nothing else: the model never reads the live fee settings, the ledger or Stripe.
            “{{ $config['assumption_label'] }}” marks a value with no public source.
            Three go-to-market costs are under review because the BID partner does the on-the-ground recruiting.
        </p>
        @include('projections.partials.assumptions', ['keys' => array_keys($config['assumptions'])])
        @include('projections.partials.scenarios')
        <p class="mt-3 text-sm text-muted">Card issuing (and so interchange) is {{ $config['card_issuing']['enabled'] ? 'on from month '.$config['card_issuing']['start_month'] : 'off' }}: the MVP has no card program.</p>
    </section>

    <x-card as="section" aria-labelledby="reference-heading">
        <h2 id="reference-heading" class="text-2xl font-semibold text-primary">Lessons from {{ $config['reference']['description'] }}</h2>
        <p class="mt-1 text-sm text-muted">A guideline only. Its numbers are not used in this model unless an assumption above says so.</p>
        <div class="mt-4 grid gap-6 lg:grid-cols-2">
            <div>
                <h3 class="font-semibold">What it assumed</h3>
                <ul class="mt-2 list-disc space-y-1.5 pl-5 text-sm">
                    @foreach ($config['reference']['facts'] as $fact)<li>{{ $fact }}</li>@endforeach
                </ul>
            </div>
            <div>
                <h3 class="font-semibold">What we take from it</h3>
                <ul class="mt-2 list-disc space-y-1.5 pl-5 text-sm">
                    @foreach ($config['reference']['lessons'] as $lesson)<li>{{ $lesson }}</li>@endforeach
                </ul>
            </div>
        </div>
    </x-card>

    <section aria-labelledby="more-heading" class="flex flex-wrap items-center gap-4">
        <h2 id="more-heading" class="sr-only">Download and method</h2>
        <x-button variant="secondary" :href="route('business-model.csv')">Download the yearly figures (CSV)</x-button>
        <a href="{{ $docs }}" class="tap-target {{ $link }}" rel="noopener">Read the method (docs/finance/MODEL.md)</a>
    </section>
</div>
</x-app-shell>
