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
    $menuRole = $viewAs ?? $role;
    $groups = $menuRole->navigationGroups();
    $grouped = $menuRole->hasGroupedNavigation();
    $nav = $menuRole->navigation();
    // Personal contacts only vote (ADR 0019): one menu item, no view-as.
    $votingOnly = (bool) $user?->isPersonalContact();
    if ($votingOnly) {
        $grouped = false;
        $nav = ['dashboard' => ['label' => 'Voting closed', 'short' => 'Closed', 'route' => 'adjudication.show', 'icon' => 'M12 3.75v16.5M7.5 20.25h9M4.5 7.5h15M6.75 7.5 3.75 13.5a3 3 0 0 0 6 0L6.75 7.5Zm10.5 0-3 6a3 3 0 0 0 6 0l-3-6Z']];
        $groups = ['main' => ['label' => null, 'items' => $nav]];
    }
    if ($role === \App\Enums\Role::Judge && $viewAs) {
        $judgeHome = ['judge-home' => ['label' => 'Judge Home', 'short' => 'Judge', 'route' => 'judge.dashboard', 'icon' => 'M3.75 12 12 4.5l8.25 7.5M5.25 10.5v9h4.5v-5.25h4.5v5.25h4.5v-9']];
        $nav = $judgeHome + $nav;
        $groups = ['judge' => ['label' => null, 'items' => $judgeHome]] + $groups;
    }
    // Unread (status New) feedback, shown on the platform admin's Feedback item.
    $unreadFeedback = $role === \App\Enums\Role::PlatformAdmin && ! $viewAs
        ? \App\Models\Feedback::where('status', \App\Enums\FeedbackStatus::New)->count() : 0;
    $initials = collect(explode(' ', $userName))
        ->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
    $menuIcon = 'M4 7h16M4 12h16M4 17h16';
    if ($grouped) {
        // Phone bar: Home, the role's two pinned pages, and a Menu button with every group.
        $tabNav = array_intersect_key($nav, array_flip(['home', ...$menuRole->pinnedNavigation()]));
        $tabMore = [];
        $sideNav = $sideMore = [];
    } else {
        // Flat menus (ADR 0025): the sidebar shows up to 5 items and the phone tab bar
        // up to 4. Past that, the first in this list stay and the rest go into a More menu.
        $priority = ['dashboard', 'wallet', 'judge-home', 'menu', 'business-model'];
        $split = function (array $items, int $max) use ($priority): array {
            if (count($items) <= $max) {
                return [$items, []];
            }
            $rank = fn ($key) => ($i = array_search($key, $priority, true)) === false ? PHP_INT_MAX : $i;
            $keep = collect(array_keys($items))->sortBy($rank)->take($max - 1)->values()->all();

            return [array_merge(array_flip($keep), array_intersect_key($items, array_flip($keep))), array_diff_key($items, array_flip($keep))];
        };
        [$sideNav, $sideMore] = $split($nav, 5);
        [$tabNav, $tabMore] = $split($nav, 4);
    }
    // Literal class names so Tailwind includes them in the build.
    $tabColumns = [1 => 'grid-cols-1', 2 => 'grid-cols-2', 3 => 'grid-cols-3', 4 => 'grid-cols-4'][count($tabNav) + ($tabMore || $grouped ? 1 : 0)];
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
        <nav aria-label="App" class="flex-1 overflow-y-auto px-4">
            @if ($grouped)
                <x-nav-groups :groups="$groups" :active="$active" :unread="$unreadFeedback" variant="side" />
            @else
            <ul class="side-nav-line space-y-0.5 pl-3.5">
                @foreach ($sideNav as $key => $item)
                    <li>
                        <a href="{{ route($item['route']) }}" @if ($active === $key) aria-current="page" @endif
                           class="side-nav-item group relative flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 font-semibold text-night-muted hover:bg-night-soft hover:text-on-night aria-[current=page]:bg-night-soft aria-[current=page]:font-bold aria-[current=page]:text-on-night">
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
                        <x-menu id="nav-more" align="left" button-class="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2 font-semibold text-night-muted hover:bg-night-soft hover:text-on-night aria-expanded:bg-night-soft aria-expanded:text-on-night">
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
            @endif
        </nav>
        <div class="mx-6 mt-4 mb-6 border-t border-night-line pt-4 text-sm text-night-muted">
            <p class="font-semibold text-on-night">Demo mode</p>
            <p class="mt-1">All people and transactions are fictional.</p>
        </div>
    </aside>

    <div class="pr-[env(safe-area-inset-right)] pl-[env(safe-area-inset-left)] lg:pl-64">
        {{-- Top bar --}}
        <header class="sticky top-0 z-20 border-b-2 border-frame bg-page">
            <div class="wrap flex min-h-16 items-center justify-between gap-4 py-2.5">
                <div class="min-w-0">
                    <a href="{{ route('home') }}" class="grid min-h-11 min-w-11 place-items-center rounded-lg lg:hidden" aria-label="Local Goods home">
                        <x-logo :mark-only="true" class="h-8 w-auto sm:hidden" />
                        <x-logo class="hidden h-7 w-auto sm:block" />
                    </a>
                    <p class="hidden truncate text-sm font-bold lg:block">{{ $context }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                    @if ($role === \App\Enums\Role::Judge && ! $votingOnly)
                        <x-menu id="view-as-menu" button-class="flex min-h-11 items-center gap-1 rounded-lg border-2 border-primary px-3 py-1.5 text-sm font-bold whitespace-nowrap text-primary hover:bg-primary-tint aria-expanded:bg-primary-tint active:translate-y-px">
                            <x-slot:trigger>View As<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg></x-slot:trigger>
                            <ul>
                                @foreach (\App\Support\ViewAs::ROLES as $option)
                                    <li><a href="{{ route('judge.view-as', $option->value) }}" class="menu-item" @if ($viewAs === $option) aria-current="true" @endif>{{ $option->label() }}</a></li>
                                @endforeach
                                <li class="mt-1 border-t border-line pt-1"><a href="{{ route('judge.view-as') }}" class="menu-item">Back to Judge Home</a></li>
                            </ul>
                        </x-menu>
                    @endif
                    <x-theme-toggle />
                    @if ($user)
                        <x-menu id="account-menu" button-class="flex min-h-11 items-center gap-2 rounded-full border-2 border-edge bg-surface py-1 pr-2 pl-1 text-sm font-bold hover:border-ink aria-expanded:border-ink active:translate-y-px sm:pr-3">
                            <x-slot:trigger>
                                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-accent font-display text-[0.8125rem] font-extrabold text-on-accent" aria-hidden="true">{{ $initials }}</span>
                                <span class="sr-only">Account: </span><span class="max-w-40 truncate max-sm:sr-only">{{ $userName }}</span>
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" class="size-4 shrink-0" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg>
                            </x-slot:trigger>
                            <div class="border-b border-line px-3 pt-2 pb-3">
                                <p class="text-xs text-muted">Signed in as</p>
                                <p class="font-semibold break-words">{{ $userName }}</p>
                                <x-badge tone="primary" :wrap="true" class="mt-1"><span class="sr-only">Role: </span>{{ $role->label() }}@if ($viewAs) · viewing as {{ $viewAs->label() }}@endif</x-badge>
                            </div>
                            <ul class="mt-1">
                                <li><a href="{{ route('account') }}" class="menu-item" @if ($active === 'account') aria-current="page" @endif>Your Account</a></li>
                                @if (! $votingOnly && ($viewAs ?? $role) === \App\Enums\Role::Employee)
                                    <li><a href="{{ route('employee.wallet') }}#history" class="menu-item">Your Receipts</a></li>
                                    <li><a href="{{ route('employee.demo-tools') }}" class="menu-item" @if ($active === 'demo-tools') aria-current="page" @endif>Demo Tools</a></li>
                                @endif
                                @if (! $votingOnly && ($viewAs ?? $role) === \App\Enums\Role::Merchant)
                                    <li><a href="{{ route('merchant.dashboard') }}#payout-history" class="menu-item">Payout History</a></li>
                                @endif
                                <li><a href="{{ route('account.data') }}" class="menu-item" @if ($active === 'account.data') aria-current="page" @endif>What We Store About You</a></li>
                                <li><a href="{{ route('account') }}#feedback" class="menu-item">Help and Feedback</a></li>
                                @unless ($votingOnly)
                                    <li><a href="{{ route('home') }}" class="menu-item">Back to the Public Site</a></li>
                                @endunless
                                <li>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="menu-item">Log Out</button>
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

        <main id="main" tabindex="-1" class="wrap pt-6 pb-8 focus:outline-none lg:pt-10 lg:pb-12">
            @if ($viewAs)
                <div role="status" class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border-2 border-accent bg-surface px-4 py-3">
                    <p class="font-semibold">You're viewing as {{ $viewAs->label() }}. This is read-only.</p>
                    <a href="{{ route('judge.view-as') }}" class="tap-target text-sm font-semibold text-primary underline underline-offset-4 hover:no-underline">Back to Judge Home</a>
                </div>
            @endif
            {{ $slot }}
        </main>

        {{-- Shown at every width (the sidebar's demo note is desktop-only). On phones it is
             the last thing on the page, with room under it for the tab bar, the Feedback
             button and the home indicator. --}}
        <footer class="wrap pb-[calc(10rem+env(safe-area-inset-bottom))] text-sm text-muted lg:pb-8">
            Demonstration project. All businesses, people and transactions are fictional.
        </footer>
    </div>

    @if ($user)
        <x-feedback-widget />
    @endif

    {{-- Mobile bottom tab bar --}}
    <nav aria-label="App" class="fixed inset-x-0 bottom-0 z-30 border-t-[6px] border-primary bg-night pr-[env(safe-area-inset-right)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)] lg:hidden">
        <ul class="grid {{ $tabColumns }}">
            @foreach ($tabNav as $key => $item)
                <li>
                    <a href="{{ route($item['route']) }}" @if ($active === $key) aria-current="page" @endif
                       class="flex min-h-15 flex-col items-center gap-1 px-1 pt-2.5 pb-2 text-xs font-bold text-night-muted hover:text-on-night aria-[current=page]:text-night-primary aria-[current=page]:underline aria-[current=page]:decoration-2 aria-[current=page]:underline-offset-4">
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
            @if ($grouped)
                <li>
                    <x-menu id="tab-menu" direction="up" width="w-[min(22rem,calc(100vw-2rem))]" panel-class="max-h-[calc(100dvh-9rem)] overflow-y-auto" button-class="flex min-h-15 w-full flex-col items-center gap-1 px-1 pt-2.5 pb-2 text-xs font-bold text-night-muted hover:text-on-night aria-expanded:text-on-night">
                        <x-slot:trigger>
                            <span class="relative">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" class="size-6" aria-hidden="true"><path d="{{ $menuIcon }}"/></svg>
                                @if ($unreadFeedback > 0)
                                    <span class="tabular absolute -top-1.5 -right-2.5 rounded-full bg-night-accent px-1.5 text-[0.65rem] font-semibold text-night">{{ $unreadFeedback }}<span class="sr-only"> unread feedback</span></span>
                                @endif
                            </span>
                            <span @class(['underline decoration-2 underline-offset-4 text-night-primary' => ($active !== null && ! array_key_exists($active, $tabNav) && array_key_exists($active, $nav))])>Menu</span>
                        </x-slot:trigger>
                        <x-nav-groups :groups="$groups" :active="$active" :unread="$unreadFeedback" variant="sheet" />
                    </x-menu>
                </li>
            @endif
            @if ($tabMore)
                <li>
                    <x-menu id="tab-more" direction="up" button-class="flex min-h-15 w-full flex-col items-center gap-1 px-1 pt-2.5 pb-2 text-xs font-bold text-night-muted hover:text-on-night aria-expanded:text-on-night">
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
