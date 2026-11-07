@props(['as' => 'h2', 'dots' => 0, 'local' => null])

{{-- A section title as a station sign (ADR 0038): a dark band in both modes
     with the title in bold capitals. The title itself is the heading (as =
     h2, h3…), so screen readers get a real heading with its own words. The
     dots on the right are decoration only (aria-hidden): one per stop on
     the line, with the local stop (1-based) in coral. --}}
<div {{ $attributes->class('station-sign') }}>
    <{{ $as }} class="station-sign-label">{{ $slot }}</{{ $as }}>
    @if ($dots > 0)
        <span class="station-sign-dots" aria-hidden="true">
            @for ($i = 1; $i <= $dots; $i++)<i @class(['is-local' => $i === $local])></i>@endfor
        </span>
    @endif
</div>
