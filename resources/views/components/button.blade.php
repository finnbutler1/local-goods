@props(['variant' => 'primary', 'size' => 'md', 'href' => null])

@php
    // Every state is visible: pointer, hover, pressed (darker and 1px down),
    // loading (aria-busy: spinner and busy text, set by app.js) and
    // unavailable (disabled or aria-disabled: grey, not see-through).
    $base = 'inline-flex cursor-pointer items-center justify-center gap-2 rounded-full font-semibold transition-colors duration-150 '
        .'active:translate-y-px aria-busy:cursor-progress '
        .'unavailable:cursor-not-allowed unavailable:border-disabled-bg unavailable:bg-disabled-bg unavailable:text-disabled-ink '
        .'unavailable:no-underline unavailable:shadow-none unavailable:translate-y-0 [&[href][aria-disabled=true]]:pointer-events-none';
    // Every size is at least 44px to tap on phones; sm keeps its compact
    // look from 640px up.
    $sizes = [
        'sm' => 'px-3.5 py-1.5 text-sm max-sm:min-h-11 max-sm:min-w-11',
        'md' => 'min-h-11 px-5 py-2.5',
        'lg' => 'min-h-11 px-6 py-3.5 text-lg',
    ];
    $variants = [
        // Teal: the default action.
        'primary' => 'bg-primary text-on-primary hover:bg-primary-hover active:bg-primary-pressed',
        // Coral: the one call to action that matters most on a screen.
        'accent' => 'bg-accent text-on-accent shadow-[0_2px_0_0_var(--color-primary-pressed)] hover:shadow-[0_1px_0_0_var(--color-primary-pressed)] hover:translate-y-px',
        'secondary' => 'border-2 border-primary bg-surface text-primary hover:bg-primary-tint active:bg-primary-tint',
        'ghost' => 'text-primary underline-offset-4 hover:underline active:text-primary-pressed',
        'danger' => 'border-2 border-danger bg-surface text-danger hover:bg-danger-bg active:bg-danger-bg',
        // For use on night panels.
        'inverse' => 'bg-on-night text-night hover:bg-night-muted',
        'inverse-outline' => 'border-2 border-night-muted text-on-night hover:bg-night-soft active:bg-night-soft',
    ];
    $classes = $base.' '.$sizes[$size].' '.$variants[$variant];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>{{ $slot }}</button>
@endif
