@extends('layouts.app')

@section('title', 'Impact projection · Local Goods')

@php
    use App\Support\Projections\Figures;

    $config = config('projections');
    $chosen = $config['scenarios'][$scenario];
    $last = $district[count($district) - 1];
    $link = 'font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary';
    $publicKeys = array_keys(array_filter($config['assumptions'], fn ($a) => $a['public']));
@endphp

@section('content')
    <div class="mx-auto max-w-6xl space-y-10 px-4 py-12 sm:px-6">
        <header class="max-w-3xl">
            <x-eyebrow class="text-accent-ink">Impact projection</x-eyebrow>
            <h1 class="mt-1 text-title font-semibold text-primary">What the program could mean for independent merchants</h1>
            <p class="mt-3 text-lg text-muted">
                A projection from stated assumptions, not a measurement: how much employees might spend at independent
                businesses, and how many visits that is, if Local Goods grows as each scenario assumes.
                Real results will be measured in a pilot.
            </p>
            <div class="mt-4">@include('projections.partials.label')</div>
        </header>

        <x-card as="section" aria-labelledby="choose-heading">
            <h2 id="choose-heading" class="sr-only">Choose a scenario and case</h2>
            @include('projections.partials.toggles', ['route' => 'projection'])
            <p class="mt-4 text-sm">
                <span class="font-semibold">{{ $chosen['name'] }}:</span> {{ $chosen['note'] }}
                @if ($chosen['source_url'])
                    Source: <a href="{{ $chosen['source_url'] }}" class="{{ $link }}" rel="noopener">{{ $chosen['source'] }}</a>.
                @endif
                The {{ strtolower($config['cases'][$case]) }} case uses the {{ strtolower($config['cases'][$case]) }} value of every
                <a href="#assumptions" class="{{ $link }}">assumption below</a>.
            </p>
        </x-card>

        <section aria-labelledby="year4-heading" class="space-y-4">
            <h2 id="year4-heading" class="text-2xl font-semibold text-primary">In year 4</h2>
            <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <x-card tone="night" class="flex flex-col">
                    <dt class="text-sm font-semibold text-night-muted">Spent at independent merchants</dt>
                    <dd class="tabular mt-2 font-display text-4xl font-semibold text-night-accent">{{ Figures::dollars($last['spend']) }}</dd>
                    <dd class="mt-1 text-sm text-night-muted">in the year</dd>
                </x-card>
                <x-card class="flex flex-col">
                    <dt class="text-sm font-semibold text-muted">Visits to local businesses</dt>
                    <dd class="tabular mt-2 font-display text-4xl font-semibold text-primary">{{ Figures::count($last['visits']) }}</dd>
                    <dd class="mt-1 text-sm text-muted">one per purchase</dd>
                </x-card>
                <x-card class="flex flex-col">
                    <dt class="text-sm font-semibold text-muted">Participating employees</dt>
                    <dd class="tabular mt-2 font-display text-4xl font-semibold text-primary">{{ Figures::count($last['employees']) }}</dd>
                    <dd class="mt-1 text-sm text-muted">at {{ Figures::count($last['employers']) }} employers in {{ $last['bids'] }} {{ ($last['bids'] === 1 ? 'BID' : 'BIDs') }}</dd>
                </x-card>
                <x-card class="flex flex-col">
                    <dt class="text-sm font-semibold text-muted">Average per merchant</dt>
                    <dd class="tabular mt-2 font-display text-4xl font-semibold text-primary">{{ Figures::dollars($last['per_merchant']) }}</dd>
                    <dd class="mt-1 text-sm text-muted">across {{ Figures::count($last['merchants']) }} participating merchants</dd>
                </x-card>
            </dl>
        </section>

        <x-card as="section" aria-labelledby="by-year-heading">
            <h2 id="by-year-heading" class="text-xl font-semibold">Spent at independent merchants, by year</h2>
            <p class="mt-1 text-sm text-muted">{{ $chosen['name'] }}, {{ strtolower($config['cases'][$case]) }} case. The table below has every number.</p>
            @include('projections.partials.bars', ['bars' => array_map(fn ($y) => ['label' => 'Year '.$y['year'], 'value' => $y['spend'], 'text' => Figures::dollars($y['spend'])], $district)])
            @include('projections.partials.district')
        </x-card>

        <section aria-labelledby="estimate-heading" class="rounded-3xl border-2 border-dashed border-muted bg-band p-6 sm:p-8">
            <h2 id="estimate-heading" class="text-2xl font-semibold text-primary">How much of this spending might stay local</h2>
            <div class="mt-3">
                @include('partials.recirculation-evidence')
            </div>
            <p class="mt-3 max-w-3xl text-lg">
                If the national study's rates held here, about <strong class="tabular font-semibold">{{ Figures::dollars($last['recirculated_if_independent']) }}</strong>
                of year 4's projected {{ Figures::dollars($last['spend']) }} would recirculate locally, against about
                {{ Figures::dollars($last['recirculated_if_chain']) }} if the same money were spent at chains. Both are estimates on top of a projection;
                the real share here could be higher or lower.
            </p>
        </section>

        <section id="assumptions" aria-labelledby="assumptions-heading" class="scroll-mt-6">
            <h2 id="assumptions-heading" class="text-2xl font-semibold text-primary">Assumptions</h2>
            <p class="mt-1 max-w-3xl text-muted">
                The drivers behind these numbers. Where there is no public source, the value is labelled
                “{{ $config['assumption_label'] }}”: Local Goods hasn't run a pilot yet, so these are what a pilot has to check.
            </p>
            @include('projections.partials.assumptions', ['keys' => $publicKeys])
            @include('projections.partials.scenarios')
            <p class="mt-4 text-sm text-muted">
                How it is worked out: each BID launches in a month set by its scenario, then enrolls its employers and merchants in a straight line.
                Spending is participating employees × monthly credit × the share of credits spent; visits are spending ÷ the average purchase.
                Credits employees don't spend stay their employer's money. The full method is in
                <a href="https://github.com/finnbutler1/local-goods/blob/main/docs/finance/MODEL.md" class="{{ $link }}" rel="noopener">the model's documentation</a>.
            </p>
        </section>
    </div>
@endsection
