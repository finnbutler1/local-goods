@props([
    'title',
    'active' => null,
    'context' => 'Golden Triangle BID · Washington, DC',
    // Only for the signed-out /styleguide: which role's menu to show.
    'preview' => null,
])

{{-- Signed-in app layout: sidebar on desktop, bottom tab bar on mobile, top
     bar with the signed-in user, their role and Log out. The menu items come
     from the user's role (App\Enums\Role::navigation()). --}}
@php
    $user = auth()->user();
    $userName = $user?->name ?? 'Design system';
    $role = $user?->role ?? $preview;
    // A judge viewing as another role sees that role's menu, read-only.
    $viewAs = \App\Support\ViewAs::current(request());
    $nav = ($viewAs ?? $role)->navigation();
    // Personal contacts only vote (ADR 0019): one menu item, no view-as.
    $votingOnly = (bool) $user?->isPersonalContact();
    if ($votingOnly) {
        $nav = ['dashboard' => ['label' => 'Check the AI', 'short' => 'Vote', 'route' => 'adjudication.show', 'icon' => 'M12 3.75v16.5M7.5 20.25h9M4.5 7.5h15M6.75 7.5 3.75 13.5a3 3 0 0 0 6 0L6.75 7.5Zm10.5 0-3 6a3 3 0 0 0 6 0l-3-6Z']];
    }
    if ($role === \App\Enums\Role::Judge && $viewAs) {
        $nav = ['judge-home' => ['label' => 'Judge home', 'short' => 'Judge', 'route' => 'judge.dashboard', 'icon' => 'M3.75 12 12 4.5l8.25 7.5M5.25 10.5v9h4.5v-5.25h4.5v5.25h4.5v-9']] + $nav;
    }
    // Unread (status New) feedback, shown on the platform admin's Feedback item.
    $unreadFeedback = $role === \App\Enums\Role::PlatformAdmin && ! $viewAs
        ? \App\Models\Feedback::where('status', \App\Enums\FeedbackStatus::New)->count() : 0;
    $initials = collect(explode(' ', $userName))
        ->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
    // Fewer things at once (ADR 0025): the sidebar shows up to 5 items and the
    // phone tab bar up to 4. Past that, the first in this list stay, in this
    // order, and the rest go into a More menu. Feedback stays visible for the platform admin;
    // Merchants (Merchant Eligibility Matching, a named AI function) for the
    // BID admin. Role::navigation() itself is unchanged.
    $priority = ['dashboard', 'wallet', 'judge-home', 'bid-admin', 'merchants', 'feedback', 'impact', 'eval', 'participation', 'menu', 'reviewers', 'adjudication'];
    $split = function (array $items, int $max) use ($priority): array {
        if (count($items) <= $max) {
            return [$items, []];
        }
        $rank = fn ($key) => ($i = array_search($key, $priority, true)) === false ? PHP_INT_MAX : $i;
        $keep = collect(array_keys($items))->sortBy($rank)->take($max - 1)->values()->all();

        // Kept items in priority order; the rest keep the role's own order.
        return [array_merge(array_flip($keep), array_intersect_key($items, array_flip($keep))), array_diff_key($items, array_flip($keep))];
    };
    [$sideNav, $sideMore] = $split($nav, 5);
    [$tabNav, $tabMore] = $split($nav, 4);
    // Literal class names so Tailwind includes them in the build.
    $tabColumns = [1 => 'grid-cols-1', 2 => 'grid-cols-2', 3 => 'grid-cols-3', 4 => 'grid-cols-4'][count($tabNav) + ($tabMore ? 1 : 0)];
    $moreIcon = 'M5 12h.01M12 12h.01M19 12h.01';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <x-brand-head />
    <title>{{ $title }} · Local Goods</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-page font-sans text-ink antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 rounded-full bg-accent px-4 py-2 font-semibold text-on-accent">Skip to content</a>
    <x-flash />

    {{-- Desktop sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col bg-night text-on-night lg:flex">
        <div class="px-6 pt-6 pb-8">
            <a href="{{ route('home') }}" class="inline-block rounded-lg" aria-label="Local Goods home">
                <x-logo :on-dark="true" class="h-8 w-auto" />
            </a>
            <p class="mt-2 text-sm font-semibold text-night-muted">Reroute your lunch.</p>
        </div>
        <nav aria-label="App" class="flex-1 px-3">
            <ul class="space-y-1">
                @foreach ($sideNav as $key => $item)
                    <li>
                        <a href="{{ route($item['route']) }}" @if ($active === $key) aria-current="page" @endif
                           class="group relative flex items-center gap-3 rounded-xl px-3 py-2.5 font-semibold text-night-muted hover:bg-night-soft hover:text-on-night aria-[current=page]:bg-night-soft aria-[current=page]:text-on-night aria-[current=page]:before:absolute aria-[current=page]:before:inset-y-2 aria-[current=page]:before:-left-3 aria-[current=page]:before:w-1 aria-[current=page]:before:rounded-full aria-[current=page]:before:bg-night-primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="size-5 shrink-0" aria-hidden="true"><path d="{{ $item['icon'] }}"/></svg>
                            {{ $item['label'] }}
                            @if ($key === 'feedback' && $unreadFeedback > 0)
                                <span class="tabular ml-auto rounded-full bg-night-accent px-2 py-0.5 text-xs font-semibold text-night">{{ $unreadFeedback }}<span class="sr-only"> unread</span></span>
                            @endif
                        </a>
                    </li>
                @endforeach
                @if ($sideMore)
                    <li>
                        <x-menu id="nav-more" align="left" button-class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 font-semibold text-night-muted hover:bg-night-soft hover:text-on-night aria-expanded:bg-night-soft aria-expanded:text-on-night">
                            <x-slot:trigger>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="size-5 shrink-0" aria-hidden="true"><path d="{{ $moreIcon }}"/></svg>
                                More
                                @if (($active !== null && array_key_exists($active, $sideMore)))<span class="ml-auto size-2 rounded-full bg-night-accent" aria-hidden="true"></span><span class="sr-only">, includes this page</span>@endif
                            </x-slot:trigger>
                            <ul>
                                @foreach ($sideMore as $key => $item)
                                    <li><a href="{{ route($item['route']) }}" class="menu-item" @if ($active === $key) aria-current="page" @endif>{{ $item['label'] }}</a></li>
                                @endforeach
                            </ul>
                        </x-menu>
                    </li>
                @endif
            </ul>
        </nav>
        <div class="m-3 rounded-xl border border-night-line p-4 text-sm text-night-muted">
            <p class="font-semibold text-on-night">Demo mode</p>
            <p class="mt-1">All people and transactions are fictional.</p>
        </div>
    </aside>

    <div class="lg:pl-64">
        {{-- Top bar --}}
        <header class="sticky top-0 z-20 border-b border-line bg-page/95 backdrop-blur supports-[backdrop-filter]:bg-page/85">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-10">
                <div class="min-w-0">
                    <a href="{{ route('home') }}" class="inline-block rounded-lg lg:hidden" aria-label="Local Goods home">
                        <x-logo :mark-only="true" class="h-8 w-auto sm:hidden" />
                        <x-logo class="hidden h-7 w-auto sm:block" />
                    </a>
                    <p class="hidden truncate text-sm font-semibold text-muted lg:block">{{ $context }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                    @if ($role === \App\Enums\Role::Judge && ! $votingOnly)
                        <x-menu id="view-as-menu" button-class="flex items-center gap-1 rounded-full border-2 border-primary px-3 py-1.5 text-sm font-semibold whitespace-nowrap text-primary hover:bg-primary-tint aria-expanded:bg-primary-tint active:translate-y-px">
                            <x-slot:trigger>View as<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg></x-slot:trigger>
                            <ul>
                                @foreach (\App\Support\ViewAs::ROLES as $option)
                                    <li><a href="{{ route('judge.view-as', $option->value) }}" class="menu-item" @if ($viewAs === $option) aria-current="true" @endif>{{ $option->label() }}</a></li>
                                @endforeach
                                <li class="mt-1 border-t border-line pt-1"><a href="{{ route('judge.view-as') }}" class="menu-item">Back to judge home</a></li>
                            </ul>
                        </x-menu>
                    @endif
                    <x-theme-toggle />
                    @if ($user)
                        <x-menu id="account-menu" button-class="flex items-center gap-2 rounded-full border border-edge bg-surface py-1 pr-2 pl-1 text-sm font-semibold hover:border-primary aria-expanded:border-primary active:translate-y-px sm:pr-3">
                            <x-slot:trigger>
                                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-accent font-display text-on-accent" aria-hidden="true">{{ $initials }}</span>
                                <span class="sr-only">Account: </span><span class="max-w-40 truncate max-sm:sr-only">{{ $userName }}</span>
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" class="size-4 shrink-0" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg>
                            </x-slot:trigger>
                            <div class="border-b border-line px-3 pt-2 pb-3">
                                <p class="text-xs text-muted">Signed in as</p>
                                <p class="font-semibold break-words">{{ $userName }}</p>
                                <x-badge tone="primary" :wrap="true" class="mt-1"><span class="sr-only">Role: </span>{{ $role->label() }}@if ($viewAs) · viewing as {{ $viewAs->label() }}@endif</x-badge>
                            </div>
                            <ul class="mt-1">
                                <li><a href="{{ route('account') }}" class="menu-item" @if ($active === 'account') aria-current="page" @endif>Your account</a></li>
                                @unless ($votingOnly)
                                    <li><a href="{{ route('home') }}" class="menu-item">Back to the public site</a></li>
                                @endunless
                                <li>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="menu-item">Log out</button>
                                    </form>
                                </li>
                            </ul>
                        </x-menu>
                    @else
                        <div class="min-w-0 text-right">
                            <p class="truncate text-sm font-semibold">{{ $userName }}</p>
                            <x-badge tone="primary" class="mt-0.5"><span class="sr-only">Role: </span>{{ $role->label() }}</x-badge>
                        </div>
                    @endif
                </div>
            </div>
        </header>

        <main id="main" tabindex="-1" class="mx-auto max-w-6xl px-4 pt-6 pb-40 focus:outline-none sm:px-6 lg:px-10 lg:pt-10 lg:pb-12">
            @if ($viewAs)
                <div role="status" class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border-2 border-accent bg-band px-4 py-3">
                    <p class="font-semibold">You're viewing as {{ $viewAs->label() }}. This is read-only.</p>
                    <a href="{{ route('judge.view-as') }}" class="text-sm font-semibold text-primary underline underline-offset-4 hover:no-underline">Back to judge home</a>
                </div>
            @endif
            {{ $slot }}
        </main>

        <footer class="mx-auto hidden max-w-6xl px-4 pb-8 text-sm text-muted sm:px-6 lg:block lg:px-10">
            Demonstration project. All businesses, people and transactions are fictional.
        </footer>
    </div>

    @if ($user)
        <x-feedback-widget />
    @endif

    {{-- Mobile bottom tab bar --}}
    <nav aria-label="App" class="fixed inset-x-0 bottom-0 z-30 border-t border-night-line bg-night pb-[env(safe-area-inset-bottom)] lg:hidden">
        <ul class="grid {{ $tabColumns }}">
            @foreach ($tabNav as $key => $item)
                <li>
                    <a href="{{ route($item['route']) }}" @if ($active === $key) aria-current="page" @endif
                       class="flex flex-col items-center gap-1 px-1 pt-2.5 pb-2 text-xs font-semibold text-night-muted hover:text-on-night aria-[current=page]:text-night-primary aria-[current=page]:underline aria-[current=page]:decoration-2 aria-[current=page]:underline-offset-4">
                        <span class="relative">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="size-6" aria-hidden="true"><path d="{{ $item['icon'] }}"/></svg>
                            @if ($key === 'feedback' && $unreadFeedback > 0)
                                <span class="tabular absolute -top-1.5 -right-2.5 rounded-full bg-night-accent px-1.5 text-[0.65rem] font-semibold text-night">{{ $unreadFeedback }}<span class="sr-only"> unread</span></span>
                            @endif
                        </span>
                        {{ $item['short'] }}
                    </a>
                </li>
            @endforeach
            @if ($tabMore)
                <li>
                    <x-menu id="tab-more" direction="up" button-class="flex w-full flex-col items-center gap-1 px-1 pt-2.5 pb-2 text-xs font-semibold text-night-muted hover:text-on-night aria-expanded:text-on-night">
                        <x-slot:trigger>
                            <span class="relative">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="size-6" aria-hidden="true"><path d="{{ $moreIcon }}"/></svg>
                                @if (array_key_exists('feedback', $tabMore) && $unreadFeedback > 0)
                                    <span class="tabular absolute -top-1.5 -right-2.5 rounded-full bg-night-accent px-1.5 text-[0.65rem] font-semibold text-night">{{ $unreadFeedback }}<span class="sr-only"> unread feedback</span></span>
                                @endif
                            </span>
                            <span @class(['underline decoration-2 underline-offset-4 text-night-primary' => ($active !== null && array_key_exists($active, $tabMore))])>More</span>
                            @if (($active !== null && array_key_exists($active, $tabMore)))<span class="sr-only">, includes this page</span>@endif
                        </x-slot:trigger>
                        <ul>
                            @foreach ($tabMore as $key => $item)
                                <li><a href="{{ route($item['route']) }}" class="menu-item" @if ($active === $key) aria-current="page" @endif>{{ $item['label'] }}</a></li>
                            @endforeach
                        </ul>
                    </x-menu>
                </li>
            @endif
        </ul>
    </nav>
</body>
</html>
