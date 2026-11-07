@props(['as' => 'p', 'center' => false])

{{-- A section's small label above its heading, after a short route line
     ending in a stop (the Transit motif, ADR 0025). Pass the text color as a
     class: text-accent-ink on light surfaces, text-night-accent on night.
     center: the stop stays inline before the first word, so a label that
     wraps on a phone is still centered as one unit. --}}
@if ($center)
    <{{ $as }} {{ $attributes->class('text-center text-sm font-semibold uppercase tracking-widest') }}><span class="route-stop mr-2 align-middle" aria-hidden="true"></span><span class="align-middle">{{ $slot }}</span></{{ $as }}>
@else
    <{{ $as }} {{ $attributes->class('flex items-center gap-2 text-sm font-semibold uppercase tracking-widest') }}><span class="route-stop" aria-hidden="true"></span><span>{{ $slot }}</span></{{ $as }}>
@endif
