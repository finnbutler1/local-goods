@extends('layouts.adaptive')

@section('title', $district->name.' map · Local Goods')
@section('active', 'district')

@php
    use App\Services\Geo\GeocodeResult;

    $link = 'font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover';
    $input = 'block w-full rounded-xl border-2 border-edge bg-surface px-3 py-2.5 focus-visible:border-primary';
    $pins = $merchants->map(fn ($m) => ['lat' => (float) $m->latitude, 'lng' => (float) $m->longitude, 'label' => $m->name.', '.$m->address,
        'href' => '#merchant-'.$m->id, 'kind' => 'merchant'])->all();
    if ($result?->lookup->isFound()) {
        $pins[] = ['lat' => $result->lookup->latitude, 'lng' => $result->lookup->longitude, 'label' => 'The address you checked: '.$result->lookup->matchedAddress, 'kind' => 'checked'];
    }
@endphp

@section('content')
    <div class="wrap">
        <header class="page-head">
            <x-station-sign as="p">District map</x-station-sign>
            <h1 class="page-title">{{ $district->name }}</h1>
            <p class="page-lede">
                Local Goods credits work only at approved independent merchants inside this boundary. The line is the one
                DC publishes for the Business Improvement District, and the same line decides whether a merchant can join.
            </p>
            <p class="mt-4"><a href="{{ route('apply') }}" class="tap-target {{ $link }}">Run an independent shop here? Apply to join <span aria-hidden="true">→</span></a></p>
        </header>

        <div class="stop-line stop-line-dense">
            <section aria-labelledby="map-heading" class="stop">
                <x-stop-number n="1" />
                <x-station-sign heading-id="map-heading">The boundary</x-station-sign>
                <div class="mt-6 lg:col-span-7">
                    <p id="map-title" class="sr-only">Map of the {{ $district->name }} boundary with {{ $merchants->count() }} merchant pins{{ $result?->lookup->isFound() ? ' and the address you checked' : '' }}. The same information is listed in text on this page.</p>
                    <x-district-map :map="$map" :pins="$pins" :streets="true" labelled-by="map-title" described-by="boundary-description" />
                </div>
                <div class="mt-4 lg:col-span-4 lg:col-start-8 lg:mt-6">
                    <p class="text-sm text-muted">
                        <span class="dd-legend mt-0 mb-2 text-ink">
                            <span class="flex items-center gap-2"><span aria-hidden="true" class="dd-key dd-key-stop"></span> Demo merchant (fictional)</span>
                            @if ($result?->lookup->isFound())
                                <span class="flex items-center gap-2"><span aria-hidden="true" class="dd-key dd-key-local"></span> The address you checked</span>
                            @endif
                        </span>
                        North is up. The map is drawn from the stored boundary, so no map service is contacted.
                    </p>
                </div>
            </section>

            <section aria-labelledby="check-heading" class="stop">
                <x-stop-number n="2" />
                <x-station-sign heading-id="check-heading">Check an address</x-station-sign>
                <form method="GET" action="{{ route('district') }}#check-result" data-busy="Checking…" class="mt-6 grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start lg:col-span-7">
                    <x-field name="address" id="address" label="DC street address" hint="Like 1700 K St NW. We ask DC's Master Address Repository where it is; the address you type is not stored.">
                        <input id="address" name="address" type="text" required maxlength="200" autocomplete="street-address" value="{{ $address }}" class="{{ $input }}"
                               aria-describedby="address-hint{{ $errors->has('address') ? ' address-error' : '' }}" @error('address') aria-invalid="true" @enderror>
                    </x-field>
                    <div class="sm:pt-7"><x-button type="submit">Check</x-button></div>
                </form>
                @if ($result)
                    <div id="check-result" tabindex="-1" role="status" data-check-result="{{ $result->inside === null ? 'unknown' : ($result->inside ? 'inside' : 'outside') }}"
                         @class(['mt-6 border-t-[6px] px-4 py-4 lg:col-span-7',
                                 'border-ok bg-ok-bg' => $result->inside === true,
                                 'border-hold bg-hold-bg' => $result->inside === false,
                                 'border-ink bg-band' => $result->inside === null])>
                        <p class="text-lg font-bold">
                            <span aria-hidden="true">{{ $result->inside === true ? '✓' : ($result->inside === false ? '✕' : '?') }}</span>
                            {{ $result->headline() }}
                        </p>
                        <p class="mt-1">{{ $result->explanation() }}</p>
                    </div>
                @endif
            </section>

            <section aria-labelledby="merchants-heading" class="stop">
                <x-stop-number n="3" />
                <x-station-sign heading-id="merchants-heading" :dots="$merchants->count()">Merchants on the map</x-station-sign>
                <p class="mt-4 text-sm font-bold">Demo data: these businesses are fictional, placed at a point on their block.</p>
                <ul class="mt-5 grid border-t-[3px] border-ink sm:grid-cols-2 sm:gap-x-6">
                    @foreach ($merchants as $merchant)
                        <li id="merchant-{{ $merchant->id }}" tabindex="-1" class="flex gap-3 border-b border-line py-3.5">
                            <span aria-hidden="true" class="dd-key dd-key-stop mt-1 shrink-0"></span>
                            <div>
                                <p class="font-bold">{{ $merchant->name }}</p>
                                <p class="text-sm text-muted">{{ ucfirst($merchant->category) }} · {{ $merchant->address }} · inside the boundary</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section aria-labelledby="about-heading" class="stop">
                <x-stop-number n="4" />
                <x-station-sign heading-id="about-heading">About the boundary</x-station-sign>
                <p id="boundary-description" class="stop-body mt-6 lg:col-span-7 lg:col-start-1 lg:mt-6">{{ $district->description }}</p>
                <p class="mt-4 max-w-[42rem] text-sm text-muted lg:col-span-7">
                    Source: <a href="{{ $district->source_url }}" class="{{ $link }}" rel="noopener">{{ $district->source_name }}</a>,
                    last edited by DC on {{ $district->source_edited_on->format('F j, Y') }}, downloaded {{ $district->retrieved_on->format('F j, Y') }}.
                    A point exactly on the line could fall either side; real addresses are never that precise.
                </p>
            </section>
        </div>
    </div>
@endsection
