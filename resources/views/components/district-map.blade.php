{{--
    The district drawn from its stored boundary (ADR 0031): no tiles, no
    outside requests. $map is a DistrictMap; $pins is a list of
    ['lat', 'lng', 'label', 'href' (or null), 'kind' => 'merchant'|'checked'|'applicant'].
    The drawing is described by $labelledBy; the same facts are in text
    next to it, so the pins are a convenience, not the only way in.
--}}
@props(['map', 'pins' => [], 'labelledBy', 'describedBy' => null])

<svg viewBox="{{ $map->viewBox() }}" role="img" aria-labelledby="{{ $labelledBy }}" @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
     class="block h-auto w-full max-h-[70vh] rounded-2xl bg-band" preserveAspectRatio="xMidYMid meet" data-district-map>
    <path d="{{ $map->path() }}" class="fill-primary-tint stroke-primary" stroke-width="4" stroke-linejoin="round" />
    @foreach ($pins as $pin)
        @continue(! $map->shows($pin['lat'], $pin['lng']))
        @php([$x, $y] = $map->project($pin['lat'], $pin['lng']))
        @if ($pin['href'] ?? null)
            <a href="{{ $pin['href'] }}" class="group" data-map-pin="{{ $pin['kind'] }}">
                <title>{{ $pin['label'] }}</title>
                <circle cx="{{ $x }}" cy="{{ $y }}" r="22" class="fill-transparent stroke-focus opacity-0 group-focus-visible:opacity-100" stroke-width="6" />
                <circle cx="{{ $x }}" cy="{{ $y }}" r="14" class="fill-accent stroke-surface group-hover:fill-primary" stroke-width="4" />
            </a>
        @else
            <g data-map-pin="{{ $pin['kind'] }}">
                <title>{{ $pin['label'] }}</title>
                <circle cx="{{ $x }}" cy="{{ $y }}" r="18" class="{{ ($pin['kind'] ?? '') === 'checked' ? 'fill-surface stroke-ink' : 'fill-accent stroke-surface' }}" stroke-width="6" />
                <circle cx="{{ $x }}" cy="{{ $y }}" r="5" class="fill-ink" />
            </g>
        @endif
    @endforeach
</svg>
