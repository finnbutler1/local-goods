@props(['variant' => 'primary', 'size' => 'md', 'href' => null])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-full font-semibold transition-colors duration-150 '
        .'disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50';
    $sizes = [
        'sm' => 'px-3.5 py-1.5 text-sm',
        'md' => 'px-5 py-2.5',
        'lg' => 'px-6 py-3.5 text-lg',
    ];
    $variants = [
        // Forest: the default action.
        'primary' => 'bg-forest text-cream hover:bg-forest-soft',
        // Amber: the one call to action that matters most on a screen.
        'accent' => 'bg-amber text-ink shadow-[0_2px_0_0_var(--color-forest)] hover:shadow-[0_1px_0_0_var(--color-forest)] hover:translate-y-px',
        'secondary' => 'border-2 border-forest text-forest hover:bg-forest hover:text-cream',
        'ghost' => 'text-forest underline-offset-4 hover:underline',
        'danger' => 'border-2 border-hold text-hold hover:bg-hold-bg',
        // For use on forest backgrounds.
        'inverse' => 'bg-cream text-forest hover:bg-paper',
        'inverse-outline' => 'border-2 border-cream text-cream hover:bg-cream hover:text-forest',
    ];
    $classes = $base.' '.$sizes[$size].' '.$variants[$variant];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>{{ $slot }}</button>
@endif
