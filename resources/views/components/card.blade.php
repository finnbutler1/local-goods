@props(['as' => 'div', 'tone' => 'paper', 'padding' => 'p-5 sm:p-6'])

@php
    $tones = [
        'paper' => 'bg-paper text-ink ring-1 ring-line/70 shadow-[0_1px_0_0_var(--color-line)]',
        'forest' => 'bg-forest text-cream',
        'tint' => 'bg-forest-tint text-ink',
        'cream' => 'bg-cream-deep text-ink',
    ];
@endphp

<{{ $as }} {{ $attributes->class(['rounded-2xl', $padding, $tones[$tone]]) }}>
    {{ $slot }}
</{{ $as }}>
