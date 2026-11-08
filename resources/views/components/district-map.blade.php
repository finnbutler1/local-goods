{{--
    The district drawn from its stored boundary (ADR 0031): no tiles, no
    outside requests. $map is a DistrictMap; $pins is a list of
    ['lat', 'lng', 'label', 'href' (or null), 'kind' => 'merchant'|'checked'|'applicant'].
    The drawing is described by $labelledBy; the same facts are in text
    next to it, so the pins are a convenience, not the only way in.
    Drawn like <x-district-diagram> (ADR 0038): map paper, a dashed ink
    boundary and, with streets, the four streets that frame the district.
--}}
@props(['map', 'pins' => [], 'labelledBy', 'describedBy' => null, 'streets' => false])

{{-- With linked pins it is a labelled group (an image can't hold links); without, one image. --}}
@php($linked = collect($pins)->contains(fn ($pin) => filled($pin['href'] ?? null)))

<svg viewBox="{{ $map->viewBox() }}" role="{{ $linked ? 'group' : 'img' }}" aria-labelledby="{{ $labelledBy }}" @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
     class="block h-auto w-full max-h-[70vh] rounded-2xl border-2 border-frame bg-band" preserveAspectRatio="xMidYMid meet" data-district-map>
    <path d="{{ $map->path() }}" class="dd-district" />
    @if ($streets)
        @foreach (\App\View\Components\DistrictDiagram::STREETS as $street)
            @php($d = 'M'.implode(' L', array_map(fn (array $point) => implode(' ', $map->project(...$point)), $street[1])))
            <path class="dd-street-case" d="{{ $d }}" />
            <path class="dd-street" d="{{ $d }}" />
        @endforeach
    @endif
    <path d="{{ $map->path() }}" class="dd-boundary" />
    @foreach ($pins as $pin)
        @continue(! $map->shows($pin['lat'], $pin['lng']))
        @php([$x, $y] = $map->project($pin['lat'], $pin['lng']))
        @if ($pin['href'] ?? null)
            <a href="{{ $pin['href'] }}" class="group" data-map-pin="{{ $pin['kind'] }}">
                <title>{{ $pin['label'] }}</title>
                <circle cx="{{ $x }}" cy="{{ $y }}" r="24" class="fill-transparent stroke-focus opacity-0 group-focus-visible:opacity-100" stroke-width="6" />
                <circle cx="{{ $x }}" cy="{{ $y }}" r="15" class="fill-surface stroke-ink group-hover:fill-primary" stroke-width="6" />
            </a>
        @else
            <g data-map-pin="{{ $pin['kind'] }}">
                <title>{{ $pin['label'] }}</title>
                @if (($pin['kind'] ?? '') === 'checked')
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="22" class="fill-accent stroke-ink" stroke-width="7" />
                @else
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="18" class="fill-surface stroke-ink" stroke-width="6" />
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="5" class="fill-ink" />
                @endif
            </g>
        @endif
    @endforeach
</svg>
