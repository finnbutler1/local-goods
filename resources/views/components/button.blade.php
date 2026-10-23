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
