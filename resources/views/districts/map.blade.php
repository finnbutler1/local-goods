@extends('layouts.app')

@section('title', $district->name.' map · Local Goods')

@php
    use App\Services\Geo\GeocodeResult;

    $link = 'font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary';
    $input = 'block w-full rounded-xl border-2 border-muted bg-surface px-3 py-2 focus-visible:border-primary';
    $pins = $merchants->map(fn ($m) => ['lat' => (float) $m->latitude, 'lng' => (float) $m->longitude, 'label' => $m->name.', '.$m->address,
        'href' => '#merchant-'.$m->id, 'kind' => 'merchant'])->all();
    if ($result?->lookup->isFound()) {
        $pins[] = ['lat' => $result->lookup->latitude, 'lng' => $result->lookup->longitude, 'label' => 'The address you checked: '.$result->lookup->matchedAddress, 'kind' => 'checked'];
    }
@endphp

@section('content')
    <div class="mx-auto max-w-6xl space-y-10 px-4 py-12 sm:px-6">
        <header class="max-w-3xl">
            <x-eyebrow class="text-accent-ink">District map</x-eyebrow>
            <h1 class="mt-1 text-title font-semibold text-primary">{{ $district->name }}</h1>
            <p class="mt-3 text-lg text-muted">
                Local Goods credits work only at approved independent merchants inside this boundary. The line is the one
                DC publishes for the Business Improvement District, and the same line decides whether a merchant can join.
            </p>
        </header>

        <div class="grid gap-8 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <section aria-labelledby="map-heading" class="space-y-3">
                <h2 id="map-heading" class="text-2xl font-semibold text-primary">The boundary</h2>
                <p id="map-title" class="sr-only">Map of the {{ $district->name }} boundary with {{ $merchants->count() }} merchant pins{{ $result?->lookup->isFound() ? ' and the address you checked' : '' }}. The same information is listed in text on this page.</p>
                <x-district-map :map="$map" :pins="$pins" labelled-by="map-title" described-by="boundary-description" />
                <p class="text-sm text-muted">
                    <span aria-hidden="true" class="mr-1 inline-block size-3 rounded-full bg-accent align-middle"></span> Demo merchant (fictional)
                    @if ($result?->lookup->isFound())
                        <span aria-hidden="true" class="ml-4 mr-1 inline-block size-3 rounded-full border-2 border-ink bg-surface align-middle"></span> The address you checked
                    @endif
                    · North is up. Drawn from the stored boundary: no map service is contacted.
                </p>
            </section>

            <section aria-labelledby="check-heading" class="space-y-4">
                <h2 id="check-heading" class="text-2xl font-semibold text-primary">Check an address</h2>
                <form method="GET" action="{{ route('district') }}#check-result" data-busy="Checking…" class="space-y-3">
                    <x-field name="address" id="address" label="DC street address" hint="Like 1700 K St NW. We ask DC's Master Address Repository where it is; the address you type is not stored.">
                        <input id="address" name="address" type="text" required maxlength="200" autocomplete="street-address" value="{{ $address }}" class="{{ $input }}"
                               aria-describedby="address-hint{{ $errors->has('address') ? ' address-error' : '' }}" @error('address') aria-invalid="true" @enderror>
                    </x-field>
                    <x-button type="submit">Check</x-button>
                </form>
                @if ($result)
                    <div id="check-result" tabindex="-1" role="status" data-check-result="{{ $result->inside === null ? 'unknown' : ($result->inside ? 'inside' : 'outside') }}"
                         @class(['rounded-2xl border-2 p-4',
                                 'border-ok bg-ok-bg' => $result->inside === true,
                                 'border-hold bg-hold-bg' => $result->inside === false,
                                 'border-line bg-band' => $result->inside === null])>
                        <p class="font-semibold">
                            <span aria-hidden="true">{{ $result->inside === true ? '✓' : ($result->inside === false ? '✕' : '?') }}</span>
                            {{ $result->headline() }}
                        </p>
                        <p class="mt-1 text-sm">{{ $result->explanation() }}</p>
                    </div>
                @endif
            </section>
        </div>

        <section aria-labelledby="merchants-heading" class="space-y-3">
            <h2 id="merchants-heading" class="text-2xl font-semibold text-primary">Merchants on the map</h2>
            <p class="text-muted">Demo data: these businesses are fictional, placed at a point on their block.</p>
            <ul class="grid gap-3 sm:grid-cols-2">
                @foreach ($merchants as $merchant)
                    <li id="merchant-{{ $merchant->id }}" tabindex="-1" class="merchant-rail rounded-2xl bg-surface p-4 ring-1 ring-line">
                        <p class="font-semibold">{{ $merchant->name }}</p>
                        <p class="text-sm text-muted">{{ ucfirst($merchant->category) }} · {{ $merchant->address }} · inside the boundary</p>
                    </li>
                @endforeach
            </ul>
        </section>

        <section aria-labelledby="about-heading" class="max-w-3xl space-y-3">
            <h2 id="about-heading" class="text-2xl font-semibold text-primary">About the boundary</h2>
            <p id="boundary-description">{{ $district->description }}</p>
            <p class="text-sm text-muted">
                Source: <a href="{{ $district->source_url }}" class="{{ $link }}" rel="noopener">{{ $district->source_name }}</a>,
                last edited by DC on {{ $district->source_edited_on->format('F j, Y') }}, downloaded {{ $district->retrieved_on->format('F j, Y') }}.
                A point exactly on the line could fall either side; real addresses are never that precise.
            </p>
        </section>
    </div>
@endsection
