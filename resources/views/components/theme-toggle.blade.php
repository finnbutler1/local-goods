{{-- Light/dark switch for the header. Pressed = dark. app.js sets the real
     state on load and remembers the choice; without JavaScript the page
     follows the system setting and this button does nothing. --}}
<button type="button" data-theme-toggle aria-pressed="false" aria-label="Dark mode" title="Dark mode"
    {{ $attributes->class('grid size-11 shrink-0 lg:size-10 place-items-center rounded-full border border-edge bg-surface text-ink hover:border-primary hover:text-primary active:translate-y-px') }}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-5 theme-icon-moon" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/></svg>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="size-5 theme-icon-sun" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/></svg>
</button>
