{{--
    The district as a transit diagram (ADR 0038, App\View\Components\DistrictDiagram).
    One image for screen readers: its title says what it shows, and the list
    after it names every stop. Stop and street labels are decoration
    (aria-hidden) and are hidden on phones, where the key carries the facts.
--}}
<figure {{ $attributes->class('district-diagram') }}>
    <svg class="dd-map" viewBox="{{ $viewBox() }}" role="img" aria-labelledby="{{ $attributes->get('id', 'district-diagram') }}-title" data-district-diagram>
        <title id="{{ $attributes->get('id', 'district-diagram') }}-title">{{ $title }}</title>
        <path class="dd-district" d="{{ $map->path() }}" />
        @foreach ($streets() as $street)
            <path class="dd-street-case" d="{{ $street }}" />
            <path class="dd-street" d="{{ $street }}" />
        @endforeach
        <path class="dd-boundary" d="{{ $map->path() }}" />
        @foreach ($streetLabels() as $label)
            <text class="dd-street-label" x="{{ $label['x'] }}" y="{{ $label['y'] }}" transform="rotate({{ $label['angle'] }} {{ $label['x'] }} {{ $label['y'] }})" text-anchor="{{ $label['anchor'] }}" aria-hidden="true">{{ $label['text'] }}</text>
        @endforeach
        <path class="dd-route dd-draw" d="{{ $route($points) }}" pathLength="1" />
        @foreach ($points as $point)
            @php([$lx, $ly, $anchor] = $labelAt($point))
            <circle class="{{ $point['local'] ? 'dd-local' : 'dd-stop' }}" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="{{ $point['local'] ? 22 : 15 }}" data-stop="{{ $point['name'] }}" @if ($point['local']) data-local-stop @endif />
            <text @class(['dd-label', 'dd-label-local' => $point['local']]) x="{{ $lx }}" y="{{ $ly }}" text-anchor="{{ $anchor }}" aria-hidden="true">@foreach ($point['lines'] as $i => $line)<tspan x="{{ $lx }}" dy="{{ $i === 0 ? 0 : 26 }}">{{ $line }}</tspan>@endforeach</text>
            @if ($point['local'])
                <text class="dd-label-sub" x="{{ $lx }}" y="{{ $ly + 26 }}" text-anchor="{{ $anchor }}" aria-hidden="true">local stop</text>
            @endif
        @endforeach
    </svg>
    <figcaption>
        <ul class="sr-only" aria-label="Demo merchants on the map">
            @foreach ($points as $point)
                <li>{{ $point['name'] }}@if ($point['local']), local stop @endif</li>
            @endforeach
        </ul>
        <ul class="dd-legend" aria-label="Map key">
            <li><span class="dd-key dd-key-stop" aria-hidden="true"></span>Demo merchants</li>
            <li><span class="dd-key dd-key-local" aria-hidden="true"></span>Local stop</li>
            <li><span class="dd-key dd-key-boundary" aria-hidden="true"></span>District boundary</li>
        </ul>
    </figcaption>
    {{ $slot }}
</figure>
