@props(['n'])

{{-- A numbered stop on the route line down a page (ADR 0038). Decorative:
     always hidden from screen readers, since the section's heading already
     says what it is. --}}
<span {{ $attributes->class('stop-number') }} aria-hidden="true">{{ $n }}</span>
