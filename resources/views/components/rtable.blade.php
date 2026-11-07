@props(['label', 'card' => true])

{{-- A table that is a table from 40rem up and a stack of cards on phones
     (the CSS is in resources/css/app.css, "Responsive tables"). Put a
     <table data-rtable="stack"> in the slot and give every body cell a
     data-label (its column name), data-primary (the card's title) or
     data-label="" (actions). From 40rem up it scrolls sideways if it must,
     so it is a focusable, labelled region. card=false leaves out even
     the text color (for tables that already style themselves). --}}
<div {{ $attributes->class([
    'rtable-wrap scroll-region',
    // Plain ruled (ADR 0038): the table's own heavy header rule and thin
    // row rules are the frame, so it never boxes itself inside a card.
    'text-ink' => $card,
]) }} role="region" tabindex="0" aria-label="{{ $label }}">
    {{ $slot }}
</div>
