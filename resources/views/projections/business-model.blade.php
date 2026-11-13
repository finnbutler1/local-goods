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
    $link = 'font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover';
    $docs = 'https://github.com/finnbutler1/local-goods/blob/main/docs/finance/MODEL.md';
    $monthText = fn (?int $m) => $m === null ? "not within {$search} months" : "month {$m} (year ".(int) ceil($m / 12).')';
    $sections = [
        ['Revenue', BusinessModel::REVENUE_LINES, 'revenue', 'Revenue'],
        ['Cost of revenue', BusinessModel::COST_OF_REVENUE_LINES, 'cost_of_revenue', 'Cost of revenue'],
        ['Operating expenses', BusinessModel::OPEX_LINES, 'opex', 'Operating expenses'],
    ];
    $th = 'px-4 py-2.5 text-right';
    $td = 'ledger-num px-4 py-2.5 text-right';
@endphp

<x-app-shell title="Business Model" active="business-model" context="Projection, not results">
<div>
    <header>
        <x-station-sign as="p">Business model</x-station-sign>
        <h1 class="page-title">Business Model</h1>
        <p class="mt-3 text-2xl font-bold tracking-tight text-ink">How Local Goods could pay for itself</p>
        <p class="page-lede">
            This is a projected P&amp;L for three ways Local Goods could grow. It is worked out month by month from
            <a href="#assumptions" class="{{ $link }}">stated assumptions</a>, each with a source or labelled an assumption to check.
            None of it is a result: Local Goods hasn't run a pilot yet.
        </p>
        <div class="mt-5">@include('projections.partials.label')</div>
    </header>

    <section aria-labelledby="choose-heading" class="rule-heavy mt-10 bg-band px-5 pb-5 sm:px-6">
        <h2 id="choose-heading" class="sr-only">Choose a scenario and case</h2>
        @include('projections.partials.toggles', ['route' => 'business-model'])
        <p class="mt-4 max-w-[52rem] text-sm">
            <span class="font-semibold">{{ $chosen['name'] }}:</span> {{ $chosen['note'] }}
            The {{ $caseName }} case uses the {{ $caseName }} value of each demand and pricing assumption; costs are the same in every case.
        </p>
    </section>

    <div class="stop-line stop-line-dense">
    <section aria-labelledby="headline-heading" class="stop">
        <x-stop-number n="1" />
        <x-station-sign heading-id="headline-heading">{{ $chosen['name'] }}, {{ $caseName }} case</x-station-sign>
        <dl class="mt-8 grid gap-x-6 gap-y-8 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rule-top">
                <dt class="text-sm font-bold">Monthly break-even</dt>
                <dd class="money mt-2 text-4xl text-primary">{{ $breakEven === null ? 'Not reached' : 'Month '.$breakEven }}</dd>
                <dd class="mt-1 text-sm text-muted">{{ $breakEven === null ? "Monthly EBITDA stays below 0 through month {$search}." : "Monthly EBITDA is above 0 from then on (checked to month {$search})." }}</dd>
            </div>
            <div class="rule-top">
                <dt class="text-sm font-bold">Peak cash needed</dt>
                <dd class="money mt-2 text-4xl">{{ Figures::dollars($peak['amount']) }}</dd>
                <dd class="mt-1 text-sm text-muted">{{ $peak['still_growing'] ? "Still growing at month {$search}." : "Deepest point: month {$peak['month']}." }} Operating losses only, never customer funds.</dd>
            </div>
            <div class="rule-top">
                <dt class="text-sm font-bold">Year 4 revenue</dt>
                <dd class="money mt-2 text-4xl">{{ Figures::dollars($year4['revenue']) }}</dd>
                <dd class="mt-1 text-sm text-muted">Gross margin {{ Figures::percent($year4['gross_margin'], 0) }}</dd>
            </div>
            <div class="rule-top">
                <dt class="text-sm font-bold">Year 4 EBITDA</dt>
                <dd @class(['money mt-2 text-4xl', 'text-danger' => $year4['ebitda'] < 0])>{{ Figures::dollars($year4['ebitda']) }}</dd>
                <dd class="mt-1 text-sm text-muted">{{ $year4['headcount'] }} people on payroll at the end of year 4</dd>
            </div>
        </dl>
        <p class="mt-8 max-w-[52rem] text-sm">
            <span class="font-semibold">Sensitivity:</span>
            @foreach ($sensitivity as $fee => $month)
                with an employer platform fee of {{ Figures::exact($fee) }} instead of {{ Figures::exact($model->assumptions($case)->value('employer_platform_fee')) }}, monthly break-even would be {{ $monthText($month) }}{{ $loop->last ? '.' : ';' }}
            @endforeach
        </p>
    </section>

    <section aria-labelledby="takes-heading" class="stop">
        <x-stop-number n="2" />
        <x-station-sign heading-id="takes-heading">What it takes</x-station-sign>
        <div class="mt-4">@include('projections.partials.label')</div>
        <p class="mt-3 max-w-[42rem] text-muted">
            This shows what one Golden Triangle BID on its own (scenario A) needs for monthly EBITDA to reach 0. It assumes the BID is
            fully enrolled, in an ordinary month, at the {{ $caseName }} case's fees and costs. The model searches month by month,
            because payroll grows in steps.
        </p>
        <dl class="mt-6 grid gap-x-6 gap-y-8 md:grid-cols-2">
            <div class="rule-heavy">
                <dt class="text-sm font-bold">At an employer platform fee of {{ Figures::exact($whatItTakes->currentFee) }}</dt>
                <dd class="money mt-2 text-2xl text-primary">
                    {{ $whatItTakes->employees === null ? 'Not reachable' : Figures::count($whatItTakes->employees).' participating employees' }}
                </dd>
                @if ($whatItTakes->employees !== null)
                    <dd class="mt-1 text-sm">That is {{ Figures::count($whatItTakes->employers) }} employers at {{ Figures::count($whatItTakes->employeesPerEmployer) }} participating employees each. The {{ $caseName }} case assumes {{ Figures::count($whatItTakes->participation) }}.</dd>
                @endif
            </div>
            <div class="rule-heavy">
                <dt class="text-sm font-bold">At {{ Figures::count($whatItTakes->participation) }} participating employees</dt>
                <dd class="money mt-2 text-2xl text-primary">
                    {{ $whatItTakes->breakEvenFee === null ? 'No fee up to $100 is enough' : 'An employer platform fee of '.Figures::exact($whatItTakes->breakEvenFee) }}
                </dd>
                <dd class="mt-1 text-sm">Per participating employee per month, against {{ Figures::exact($whatItTakes->currentFee) }} assumed. Benefit platforms are estimated at about $4 to $12.</dd>
            </div>
        </dl>
    </section>

    <section aria-labelledby="chart-heading" class="stop">
        <x-stop-number n="3" />
        <x-station-sign heading-id="chart-heading">Revenue and EBITDA by year</x-station-sign>
        <p class="mt-4 text-sm text-muted">Bars below zero are losses. The P&amp;L table below has every number.</p>
        <div class="grid gap-x-10 gap-y-8 md:grid-cols-2">
            <div>
                <h3 class="mt-4 text-sm font-bold">Revenue</h3>
                @include('projections.partials.bars', ['bars' => array_map(fn ($y) => ['label' => 'Year '.$y['year'], 'value' => $y['revenue'], 'text' => Figures::dollars($y['revenue'])], $years)])
            </div>
            <div>
                <h3 class="mt-4 text-sm font-bold">EBITDA</h3>
                @include('projections.partials.bars', ['bars' => array_map(fn ($y) => ['label' => 'Year '.$y['year'], 'value' => $y['ebitda'], 'text' => Figures::dollars($y['ebitda'])], $years)])
            </div>
        </div>
    </section>

    <section aria-labelledby="pnl-heading" class="stop">
        <x-stop-number n="4" />
        <x-station-sign heading-id="pnl-heading">Projected P&amp;L</x-station-sign>
        <p class="mt-4 max-w-[52rem] text-sm text-muted">
            {{ $chosen['name'] }}, {{ $caseName }} case, by year. Revenue is only Local Goods' own fees. The credits employers buy pass through to
            merchants and are customer funds, never revenue; credits left unspent stay the employer's money.
        </p>
        <x-rtable class="mt-4" label="Projected P&L table">
            <table data-rtable="stack" class="w-full min-w-2xl text-left text-sm">
                <caption class="sr-only">Projected profit and loss by year</caption>
                <thead>
                    <tr>
                        <th scope="col" class="py-2.5 pr-4">Line</th>
                        @foreach ($years as $y)<th scope="col" class="{{ $th }}">Year {{ $y['year'] }}</th>@endforeach
                    </tr>
                </thead>
                <tbody class="tabular divide-y divide-line">
                    @foreach ($sections as [$heading, $lines, $total, $totalLabel])
                        @foreach ($lines as $key => $label)
                            <tr>
                                <th scope="row" data-primary class="py-2.5 pr-4 font-normal">{{ $label }}</th>
                                @foreach ($years as $y)<td data-label="Year {{ $y['year'] }}" class="{{ $td }}">{{ Figures::dollars($y[$key]) }}</td>@endforeach
                            </tr>
                        @endforeach
                        <tr class="border-t-2 border-ink">
                            <th scope="row" data-primary class="py-2.5 pr-4 font-bold">{{ $totalLabel }}</th>
                            @foreach ($years as $y)<td data-label="Year {{ $y['year'] }}" class="{{ $td }} font-bold">{{ Figures::dollars($y[$total]) }}</td>@endforeach
                        </tr>
                        @if ($total === 'cost_of_revenue')
                            <tr class="bg-band">
                                <th scope="row" data-primary class="py-2.5 pr-4 pl-2 font-bold">Gross profit (margin)</th>
                                @foreach ($years as $y)<td data-label="Year {{ $y['year'] }}" class="{{ $td }} font-bold">{{ Figures::dollars($y['gross_profit']) }} <span class="font-normal text-muted">({{ Figures::percent($y['gross_margin'], 0) }})</span></td>@endforeach
                            </tr>
                        @endif
                    @endforeach
                    <tr class="border-y-[3px] border-double border-ink">
                        <th scope="row" data-primary class="py-3 pr-4 font-extrabold">EBITDA</th>
                        @foreach ($years as $y)<td data-label="Year {{ $y['year'] }}" class="{{ $td }} py-3 font-bold">{{ Figures::dollars($y['ebitda']) }}</td>@endforeach
                    </tr>
                    <tr>
                        <th scope="row" data-primary class="py-2.5 pr-4 font-normal">People on payroll, year end</th>
                        @foreach ($years as $y)<td data-label="Year {{ $y['year'] }}" class="{{ $td }}">{{ $y['headcount'] }}</td>@endforeach
                    </tr>
                </tbody>
            </table>
        </x-rtable>
    </section>

    <section aria-labelledby="compare-heading" class="stop">
        <x-stop-number n="5" />
        <x-station-sign heading-id="compare-heading">The three scenarios side by side</x-station-sign>
        <p class="mt-4 text-sm text-muted">All three in the {{ $caseName }} case.</p>
        <x-rtable class="mt-4" label="Scenario comparison table">
            <table data-rtable="stack" class="w-full min-w-3xl text-left text-sm">
                <caption class="sr-only">Revenue and EBITDA by year, break-even and peak cash for each scenario</caption>
                <thead>
                    <tr>
                        <th scope="col" class="py-2.5 pr-4">Scenario</th>
                        @foreach ([1, 2, 3, 4] as $n)<th scope="col" class="{{ $th }}">Year {{ $n }} revenue / EBITDA</th>@endforeach
                        <th scope="col" class="{{ $th }}">Monthly break-even</th>
                        <th scope="col" class="{{ $th }}">Peak cash needed</th>
                    </tr>
                </thead>
                <tbody class="tabular divide-y divide-line">
                    @foreach ($comparison as $key => $p)
                        <tr>
                            <th scope="row" data-primary class="py-2.5 pr-4 font-bold">{{ $config['scenarios'][$key]['name'] }}</th>
                            @foreach ($p->years() as $y)<td data-label="Year {{ $y['year'] }} revenue / EBITDA" class="{{ $td }} whitespace-nowrap">{{ Figures::dollars($y['revenue']) }} / {{ Figures::dollars($y['ebitda']) }}</td>@endforeach
                            <td data-label="Monthly break-even" class="{{ $td }}">{{ $p->breakEvenMonth() === null ? 'Not reached' : 'Month '.$p->breakEvenMonth() }}</td>
                            <td data-label="Peak cash needed" class="{{ $td }}">{{ Figures::dollars($p->peakCashNeeded()['amount']) }}{{ $p->peakCashNeeded()['still_growing'] ? ', still growing' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-rtable>
    </section>

    <div class="stop">
        <x-stop-number n="6" />
        <div class="grid gap-x-8 gap-y-14 lg:grid-cols-2">
            <section aria-labelledby="units-heading">
                <x-station-sign heading-id="units-heading">Unit economics, year 4</x-station-sign>
                <dl class="mt-6 border-t-[3px] border-ink text-sm">
                    @foreach ([
                        'Revenue per participating employee per month' => Figures::exact($units['revenue_per_employee_month']),
                        'Cost of revenue per participating employee per month' => Figures::exact($units['cost_of_revenue_per_employee_month']),
                        'Contribution per participating employee per month' => Figures::exact($units['contribution_per_employee_month']),
                        'Revenue per BID per year' => Figures::dollars($units['revenue_per_bid_year']),
                        'Gross profit per BID per year' => Figures::dollars($units['gross_profit_per_bid_year']),
                    ] as $label => $value)
                        <div class="flex justify-between gap-4 border-b border-line py-2.5"><dt>{{ $label }}</dt><dd class="ledger-num font-semibold">{{ $value }}</dd></div>
                    @endforeach
                </dl>
                <p class="mt-3 text-sm text-muted">Year 4 totals divided by employee-months and by the average number of BIDs running that year. BIDs still enrolling employers bring this down.</p>
            </section>

            <section aria-labelledby="funds-heading">
                <x-station-sign heading-id="funds-heading">Customer funds held: a promise to pay</x-station-sign>
                <p class="mt-4 text-sm text-muted">
                    This is money that belongs to employers, employees and merchants, held by Stripe. It is not revenue or operating cash.
                    Unspent credits stay the employer's money: breakage is excluded from revenue by policy.
                </p>
                <x-rtable class="mt-4" label="Customer funds table" :card="false">
                    <table data-rtable="stack" class="w-full text-left text-sm">
                        <caption class="sr-only">Customer funds held at each year's end</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="py-2 pr-3">Year end</th>
                                <th scope="col" class="py-2 pr-3 text-right">Unspent employee balances</th>
                                <th scope="col" class="py-2 pr-3 text-right">Owed to merchants</th>
                                <th scope="col" class="py-2 text-right">Returned to employers in the year</th>
                            </tr>
                        </thead>
                        <tbody class="tabular divide-y divide-line">
                            @foreach ($projection->customerFunds() as $f)
                                <tr>
                                    <th scope="row" data-primary class="py-2 pr-3 font-bold">Year {{ $f['year'] }}</th>
                                    <td data-label="Unspent employee balances" class="ledger-num py-2 pr-3 text-right">{{ Figures::dollars($f['employee_balances']) }}</td>
                                    <td data-label="Owed to merchants" class="ledger-num py-2 pr-3 text-right">{{ Figures::dollars($f['owed_to_merchants']) }}</td>
                                    <td data-label="Returned to employers in the year" class="ledger-num py-2 text-right">{{ Figures::dollars($f['returned_to_employers']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-rtable>
            </section>
        </div>
    </div>

    <section aria-labelledby="district-heading" class="stop">
        <x-stop-number n="7" />
        <x-station-sign heading-id="district-heading">District impact</x-station-sign>
        <p class="mt-4 text-sm text-muted">The same numbers as the public <a href="{{ route('projection', ['scenario' => $scenario, 'case' => $case]) }}" class="{{ $link }}">impact projection</a>.</p>
        @include('projections.partials.district', ['district' => $projection->districtImpact()])
        <div class="rule-heavy mt-10 bg-band px-5 pb-6 sm:px-8">
            @include('partials.recirculation-evidence')
            <p class="mt-3 max-w-3xl text-sm">
                If the study's rates held here, about {{ Figures::dollars($projection->districtImpact()[3]['recirculated_if_independent']) }} of year 4's projected
                spending would recirculate locally. This is an estimate on top of a projection.
            </p>
        </div>
    </section>

    <section id="assumptions" aria-labelledby="assumptions-heading" class="stop scroll-mt-6">
        <x-stop-number n="8" />
        <x-station-sign heading-id="assumptions-heading">Assumptions and sources</x-station-sign>
        <div class="mt-4">@include('projections.partials.label')</div>
        <p class="mt-3 max-w-[48rem] text-muted">
            Every number above comes from these, and nothing else: the model never reads the live fee settings, the ledger or Stripe.
            The other way round, the product's employer platform fee per participating employee uses the base employer platform fee below:
            it is <span data-employer-fee-status>{{ \App\Support\EmployerPlatformFee::status() }}</span>.
            “{{ $config['assumption_label'] }}” marks a value with no public source.
            Three go-to-market costs are under review because the model assumes the BID does the on-the-ground recruiting.
        </p>
        <div class="mt-3">
            @include('projections.partials.assumptions', ['keys' => array_keys($config['assumptions'])])
            @include('projections.partials.scenarios')
        </div>
        <p class="mt-3 text-sm text-muted">Card issuing (and so interchange) is {{ $config['card_issuing']['enabled'] ? 'on from month '.$config['card_issuing']['start_month'] : 'off' }}: the MVP has no card program.</p>
    </section>

    <section aria-labelledby="reference-heading" class="stop">
        <x-stop-number n="9" />
        <x-station-sign heading-id="reference-heading">Lessons from {{ $config['reference']['description'] }}</x-station-sign>
        <p class="mt-4 text-sm text-muted">A guideline only. Its numbers are not used in this model unless an assumption above says so.</p>
        <div class="mt-6 grid gap-x-8 gap-y-8 lg:grid-cols-2">
            <div class="rule-top">
                <h3 class="font-bold">What it assumed</h3>
                <ul class="mt-2 text-sm">
                    @foreach ($config['reference']['facts'] as $fact)<li class="border-b border-line py-2">{{ $fact }}</li>@endforeach
                </ul>
            </div>
            <div class="rule-top">
                <h3 class="font-bold">What we take from it</h3>
                <ul class="mt-2 text-sm">
                    @foreach ($config['reference']['lessons'] as $lesson)<li class="border-b border-line py-2">{{ $lesson }}</li>@endforeach
                </ul>
            </div>
        </div>
    </section>
    </div>

    <section aria-labelledby="more-heading" class="rule-heavy flex flex-wrap items-center gap-4 pt-6">
        <h2 id="more-heading" class="sr-only">Download and method</h2>
        <x-button variant="secondary" :href="route('business-model.csv')">Download the yearly figures (CSV)</x-button>
        <a href="{{ $docs }}" class="tap-target {{ $link }}" rel="noopener">Read the method (docs/finance/MODEL.md)</a>
    </section>
</div>
</x-app-shell>
