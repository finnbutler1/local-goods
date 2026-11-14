@extends('layouts.adaptive')

@section('title', 'Impact projection · Local Goods')

@php
    use App\Support\Projections\Figures;

    $config = config('projections');
    $chosen = $config['scenarios'][$scenario];
    $last = $district[count($district) - 1];
    $link = 'font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover';
    $publicKeys = array_keys(array_filter($config['assumptions'], fn ($a) => $a['public']));
@endphp

@section('content')
    <div class="wrap pb-6">
        <header class="page-head">
            <x-station-sign as="p">Impact projection</x-station-sign>
            <h1 class="page-title">What the program could mean for independent merchants</h1>
            <p class="page-lede">
                A projection from stated assumptions, not a measurement. It shows how much employees might spend at independent
                businesses, and how many visits that is, if Local Goods grows as each scenario assumes.
                Real results will be measured in a pilot.
            </p>
            <div class="mt-5">@include('projections.partials.label')</div>
        </header>

        <section aria-labelledby="choose-heading" class="rule-heavy mt-10 bg-band px-5 pb-5 sm:px-6">
            <h2 id="choose-heading" class="sr-only">Choose a scenario and case</h2>
            @include('projections.partials.toggles', ['route' => 'projection'])
            <p class="mt-4 max-w-[52rem] text-sm">
                <span class="font-semibold">{{ $chosen['name'] }}:</span> {{ $chosen['note'] }}
                @if ($chosen['source_url'])
                    Source: <a href="{{ $chosen['source_url'] }}" class="{{ $link }}" rel="noopener">{{ $chosen['source'] }}</a>.
                @endif
                The {{ strtolower($config['cases'][$case]) }} case uses the {{ strtolower($config['cases'][$case]) }} value of every
                <a href="#assumptions" class="{{ $link }}">assumption below</a>.
            </p>
        </section>

        <div class="stop-line stop-line-dense">
            <section aria-labelledby="year4-heading" class="stop">
                <x-stop-number n="1" />
                <x-station-sign heading-id="year4-heading">In year 4</x-station-sign>
                <dl class="mt-8 grid gap-x-6 gap-y-8 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rule-top">
                        <dt class="text-sm font-bold">Spent at independent merchants</dt>
                        <dd class="money mt-2 text-4xl text-primary sm:text-5xl">{{ Figures::dollars($last['spend']) }}</dd>
                        <dd class="mt-1 text-sm text-muted">in the year</dd>
                    </div>
                    <div class="rule-top">
                        <dt class="text-sm font-bold">Visits to local businesses</dt>
                        <dd class="money mt-2 text-4xl sm:text-5xl">{{ Figures::count($last['visits']) }}</dd>
                        <dd class="mt-1 text-sm text-muted">one per purchase</dd>
                    </div>
                    <div class="rule-top">
                        <dt class="text-sm font-bold">Participating employees</dt>
                        <dd class="money mt-2 text-4xl sm:text-5xl">{{ Figures::count($last['employees']) }}</dd>
                        <dd class="mt-1 text-sm text-muted">at {{ Figures::count($last['employers']) }} employers in {{ $last['bids'] }} {{ ($last['bids'] === 1 ? 'BID' : 'BIDs') }}</dd>
                    </div>
                    <div class="rule-top">
                        <dt class="text-sm font-bold">Average per merchant</dt>
                        <dd class="money mt-2 text-4xl sm:text-5xl">{{ Figures::dollars($last['per_merchant']) }}</dd>
                        <dd class="mt-1 text-sm text-muted">across {{ Figures::count($last['merchants']) }} participating merchants</dd>
                    </div>
                </dl>
            </section>

            <section aria-labelledby="by-year-heading" class="stop">
                <x-stop-number n="2" />
                <x-station-sign heading-id="by-year-heading">Spent at independent merchants, by year</x-station-sign>
                <p class="mt-4 text-sm text-muted">{{ $chosen['name'] }}, {{ strtolower($config['cases'][$case]) }} case. The table below has every number.</p>
                <div class="mt-4 max-w-3xl">
                    @include('projections.partials.bars', ['bars' => array_map(fn ($y) => ['label' => 'Year '.$y['year'], 'value' => $y['spend'], 'text' => Figures::dollars($y['spend'])], $district)])
                </div>
                <div class="mt-8">
                    @include('projections.partials.district')
                </div>
            </section>

            <section aria-labelledby="estimate-heading" class="stop">
                <x-stop-number n="3" />
                <x-station-sign heading-id="estimate-heading">How much of this spending might stay local</x-station-sign>
                <div class="rule-heavy mt-8 bg-band px-5 pb-6 sm:px-8 sm:pb-8">
                    @include('partials.recirculation-evidence')
                    <p class="mt-4 max-w-3xl text-lg">
                        If the national study's rates held here, about <strong class="tabular font-semibold">{{ Figures::dollars($last['recirculated_if_independent']) }}</strong>
                        of year 4's projected {{ Figures::dollars($last['spend']) }} would recirculate locally, against about
                        {{ Figures::dollars($last['recirculated_if_chain']) }} if the same money were spent at chains. Both are estimates on top of a projection;
                        the real share here could be higher or lower.
                    </p>
                </div>
            </section>

            <section id="assumptions" aria-labelledby="assumptions-heading" class="stop scroll-mt-6">
                <x-stop-number n="4" />
                <x-station-sign heading-id="assumptions-heading">Assumptions</x-station-sign>
                <p class="mt-4 max-w-[42rem] text-muted">
                    The drivers behind these numbers. Where there is no public source, the value is labelled
                    “{{ $config['assumption_label'] }}”: Local Goods hasn't run a pilot yet, so these are what a pilot has to check.
                </p>
                <div class="mt-3">
                    @include('projections.partials.assumptions', ['keys' => $publicKeys])
                    @include('projections.partials.scenarios')
                </div>
                <p class="mt-4 max-w-[52rem] text-sm text-muted">
                    How it is worked out: each BID launches in a month set by its scenario, then enrolls its employers and merchants in a straight line.
                    Spending is participating employees × monthly credit × the share of credits spent; visits are spending ÷ the average purchase.
                    Credits employees don't spend are never Local Goods revenue. The full method is in
                    <a href="https://github.com/finnbutler1/local-goods/blob/main/docs/finance/MODEL.md" class="{{ $link }}" rel="noopener">the model's documentation</a>.
                </p>
            </section>
        </div>
    </div>
@endsection
