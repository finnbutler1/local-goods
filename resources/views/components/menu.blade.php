@props([
    'id',
    // Where the list opens: below the button (down) or above it (up, for the phone tab bar).
    'direction' => 'down',
    'align' => 'right',
    'buttonClass' => '',
    'panelClass' => '',
])

{{-- Disclosure menu (ADR 0025): a real button with aria-expanded and
     aria-controls, and a list of links and buttons. resources/js/app.js adds
     the keyboard: Enter/Space open, arrows and Home/End move, Escape closes
     and returns focus to the button, clicking or tabbing away closes.
     Without JavaScript the list simply shows, so nothing is out of reach. --}}
<div data-menu {{ $attributes->class('relative') }}>
    <button type="button" id="{{ $id }}-button" aria-expanded="false" aria-controls="{{ $id }}" data-menu-button @class([$buttonClass])>{{ $trigger }}</button>
    <div id="{{ $id }}" data-menu-panel @class([
        'menu-panel z-40 w-64 max-w-[calc(100vw-2rem)] rounded-xl border border-line bg-surface p-2 text-ink shadow-lg',
        'top-full mt-2' => $direction === 'down',
        'bottom-full mb-2' => $direction === 'up',
        'right-0' => $align === 'right',
        'left-0' => $align === 'left',
        $panelClass,
    ])>
        {{ $slot }}
    </div>
</div>
