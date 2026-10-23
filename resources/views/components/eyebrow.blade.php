@props(['as' => 'p'])

{{-- A section's small label above its heading, after a short route line
     ending in a stop (the Transit motif, ADR 0025). Pass the text color as a
     class: text-accent-ink on light surfaces, text-night-accent on night. --}}
<{{ $as }} {{ $attributes->class('flex items-center gap-2 text-sm font-semibold uppercase tracking-widest') }}><span class="route-stop" aria-hidden="true"></span><span>{{ $slot }}</span></{{ $as }}>
