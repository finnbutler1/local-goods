@props(['as' => 'div', 'tone' => 'surface', 'padding' => 'p-5 sm:p-6'])

@php
    $tones = [
        'surface' => 'bg-surface text-ink ring-1 ring-line shadow-[0_1px_0_0_var(--color-line)]',
        // Dark in both modes, like the sidebar: headline numbers.
        'night' => 'bg-night text-on-night',
        'tint' => 'bg-primary-tint text-ink',
        'band' => 'bg-band text-ink',
    ];
@endphp

<{{ $as }} {{ $attributes->class(['rounded-2xl', $padding, $tones[$tone]]) }}>
    {{ $slot }}
</{{ $as }}>
