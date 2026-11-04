@props([
    // Role::navigationGroups(): [id => ['label' => ?string, 'items' => [key => item]]]
    'groups',
    'active' => null,
    'unread' => 0,
    // 'side' (dark sidebar) or 'sheet' (the phone Menu panel). Ids differ so both can be on one page.
    'variant' => 'side',
])

{{-- Grouped menu (collapsible). Each heading is a real button with aria-expanded
     and aria-controls; resources/js/app.js toggles it. The group holding the
     current page starts open. Without JavaScript every group shows, so nothing
     is out of reach. --}}
@php
    $side = $variant === 'side';
    $itemClass = $side
        ? 'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 font-semibold text-night-muted hover:bg-night-soft hover:text-on-night aria-[current=page]:bg-night-soft aria-[current=page]:text-on-night aria-[current=page]:before:absolute aria-[current=page]:before:inset-y-2 aria-[current=page]:before:-left-3 aria-[current=page]:before:w-1 aria-[current=page]:before:rounded-full aria-[current=page]:before:bg-night-primary'
        : 'menu-item';
    $headClass = $side
        ? 'group/head flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left font-semibold text-night-muted hover:bg-night-soft hover:text-on-night'
        : 'group/head flex min-h-11 w-full items-center gap-2 rounded-lg px-3 py-2.5 text-left text-sm font-semibold text-primary hover:bg-band';
@endphp

<ul @class(['space-y-1' => $side])>
    @foreach ($groups as $groupId => $group)
        @php
            $holdsActive = $active !== null && array_key_exists($active, $group['items']);
            $panelId = "nav-{$variant}-{$groupId}";
        @endphp
        @if ($group['label'] === null)
            @foreach ($group['items'] as $key => $item)
                <li>
                    <a href="{{ route($item['route']) }}" @if ($active === $key) aria-current="page" @endif class="{{ $itemClass }}">
                        @if ($side)<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="size-5 shrink-0" aria-hidden="true"><path d="{{ $item['icon'] }}"/></svg>@endif
                        {{ $item['label'] }}
                    </a>
                </li>
            @endforeach
        @else
            <li data-nav-group>
                <button type="button" id="{{ $panelId }}-button" aria-expanded="{{ $holdsActive ? 'true' : 'false' }}" aria-controls="{{ $panelId }}" data-nav-group-button class="{{ $headClass }}">
                    <span class="flex-1">{{ $group['label'] }}@if ($holdsActive)<span class="sr-only">, includes this page</span>@endif</span>
                    @if (array_key_exists('feedback', $group['items']) && $unread > 0)
                        <span class="tabular rounded-full bg-night-accent px-2 py-0.5 text-xs font-semibold text-night group-aria-expanded/head:hidden">{{ $unread }}<span class="sr-only"> unread</span></span>
                    @endif
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" class="size-4 shrink-0 motion-safe:transition-transform group-aria-expanded/head:rotate-180" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg>
                </button>
                <ul id="{{ $panelId }}" data-nav-group-panel @class(['mt-1 space-y-1', 'ml-5 border-l border-night-line pl-3' => $side, 'ml-2 border-l border-line pl-2' => ! $side])>
                    @foreach ($group['items'] as $key => $item)
                        <li>
                            <a href="{{ route($item['route']) }}" @if ($active === $key) aria-current="page" @endif class="{{ $itemClass }}">
                                <span class="flex-1">{{ $item['label'] }}</span>
                                @if ($key === 'feedback' && $unread > 0)
                                    <span class="tabular rounded-full bg-night-accent px-2 py-0.5 text-xs font-semibold text-night">{{ $unread }}<span class="sr-only"> unread</span></span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </li>
        @endif
    @endforeach
</ul>
