@props(['tone' => 'neutral'])

@php
    $tones = [
        'neutral' => 'bg-cream-deep text-ink',
        'forest' => 'bg-forest text-cream',
        'ok' => 'bg-ok-bg text-ok',
        'hold' => 'bg-hold-bg text-hold',
        'amber' => 'bg-amber text-ink',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap', $tones[$tone]]) }}>{{ $slot }}</span>
