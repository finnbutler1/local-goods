@props(['as' => 'div', 'tone' => 'surface', 'padding' => 'p-5 sm:p-6'])

@php
    $tones = [
        // Raised paper with an ink frame, like a panel on a station wall.
        'surface' => 'bg-surface text-ink border-2 border-frame',
        // A station sign, dark in both modes, like the sidebar: headline numbers.
        'night' => 'bg-night text-on-night',
        'tint' => 'bg-primary-tint text-ink',
        'band' => 'bg-band text-ink',
    ];
@endphp

<{{ $as }} {{ $attributes->class(['rounded-2xl', $padding, $tones[$tone]]) }}>
    {{ $slot }}
</{{ $as }}>
