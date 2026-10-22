@props([
    'status' => session('status'),
    'notice' => session('notice'),
    'error' => session('error'),
    'focus' => true,
    // Each layout shows it once, floating at the top of the screen, so it is
    // seen even when the redirect jumps to an #anchor further down. The
    // /styleguide samples pass false to show it in place.
    'floating' => true,
])

{{-- The result of the last action, as a toast: status (✓ it worked), notice
     (it worked, but something is still waiting, e.g. held for review) or
     error (nothing happened). After the page reloads, resources/js/app.js
     moves focus here (data-flash) so screen readers announce it, unless a
     field has an error, which wins. × or Escape dismisses it; status and
     notice fade out on their own unless reduced motion is on. --}}
@php
    // Fortify flashes a key, not a sentence, after a password change.
    if ($status === 'password-updated') {
        $status = 'Your password was changed.';
    }
    // Error first, so it is the one that gets focus.
    $messages = array_filter(['error' => $error, 'notice' => $notice, 'status' => $status], 'filled');
    $tones = [
        'status' => ['role' => 'status', 'class' => 'border-ok bg-ok-bg text-ok', 'icon' => 'm5 12.5 4.5 4.5L19 7.5'],
        'notice' => ['role' => 'status', 'class' => 'border-amber bg-cream-deep text-ink', 'icon' => 'M12 7.5V12l3 2M20.25 12a8.25 8.25 0 1 1-16.5 0 8.25 8.25 0 0 1 16.5 0Z'],
        'error' => ['role' => 'alert', 'class' => 'border-hold bg-hold-bg text-hold', 'icon' => 'M4.5 20.25V4.5m0 0h12l-2.25 4.5 2.25 4.5h-12'],
    ];
@endphp

@if ($messages)
    <div data-toasts @class([
        'pointer-events-none fixed inset-x-0 top-3 z-50 flex flex-col items-center gap-2 px-4' => $floating,
        'space-y-3' => ! $floating,
    ])>
        @foreach ($messages as $tone => $text)
            <div role="{{ $tones[$tone]['role'] }}" data-toast="{{ $tone }}" @if ($tone !== 'error') data-toast-autohide @endif
                 @if ($focus) tabindex="-1" data-flash @endif
                 {{ $attributes->class([
                     'flex w-full items-start gap-3 rounded-2xl border-2 py-3 pr-2 pl-4 font-semibold transition-opacity duration-500',
                     'pointer-events-auto max-w-lg shadow-lg' => $floating,
                     $tones[$tone]['class'],
                 ]) }}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 size-5 shrink-0" aria-hidden="true"><path d="{{ $tones[$tone]['icon'] }}"/></svg>
                <p class="min-w-0 flex-1 py-px break-words">{{ $text }}</p>
                <button type="button" data-toast-dismiss aria-label="Dismiss message"
                        class="-my-2 grid size-11 shrink-0 place-items-center rounded-full hover:bg-paper/70">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="size-5" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
        @endforeach
    </div>
@endif
