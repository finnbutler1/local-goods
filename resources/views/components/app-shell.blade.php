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
    // Literal class names so Tailwind includes them in the build.
    $tabColumns = [1 => 'grid-cols-1', 2 => 'grid-cols-2', 3 => 'grid-cols-3', 4 => 'grid-cols-4', 5 => 'grid-cols-5', 6 => 'grid-cols-6'][count($nav)];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1f3d2b">
    <title>{{ $title }} · Local Goods</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-cream font-sans text-ink antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 rounded-full bg-amber px-4 py-2 font-semibold text-ink">Skip to content</a>

    {{-- Desktop sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col bg-forest text-cream lg:flex">
        <div class="awning h-2" aria-hidden="true"></div>
        <div class="px-6 pt-6 pb-8">
            <a href="{{ route('home') }}" class="inline-block font-display text-2xl font-semibold tracking-tight">
                Local Goods
            </a>
            <p class="mt-1 text-sm text-sage">Spend local. Prove it.</p>
        </div>
        <nav aria-label="App" class="flex-1 px-3">
            <ul class="space-y-1">
                @foreach ($nav as $key => $item)
                    <li>
                        <a href="{{ route($item['route']) }}" @if ($active === $key) aria-current="page" @endif
                           class="group flex items-center gap-3 rounded-xl px-3 py-2.5 font-semibold text-sage hover:bg-forest-soft hover:text-cream aria-[current=page]:bg-cream aria-[current=page]:text-forest">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="size-5 shrink-0" aria-hidden="true"><path d="{{ $item['icon'] }}"/></svg>
                            {{ $item['label'] }}
                            @if ($key === 'feedback' && $unreadFeedback > 0)
                                <span class="tabular ml-auto rounded-full bg-amber px-2 py-0.5 text-xs font-semibold text-ink">{{ $unreadFeedback }}<span class="sr-only"> unread</span></span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
        <div class="m-3 rounded-xl border border-forest-soft p-4 text-sm text-sage">
            <p class="font-semibold text-cream">Demo mode</p>
            <p class="mt-1">All people and transactions are fictional.</p>
            @unless ($votingOnly)
                <a href="{{ route('home') }}" class="mt-2 inline-block font-semibold text-cream underline underline-offset-4 hover:no-underline">Back to the public site</a>
            @endunless
        </div>
        <a href="{{ route('account') }}" @if ($active === 'account') aria-current="page" @endif
           class="mx-3 mb-2 rounded-xl px-3 py-2 text-sm font-semibold text-sage hover:bg-forest-soft hover:text-cream aria-[current=page]:bg-cream aria-[current=page]:text-forest">Your account</a>
        <form method="POST" action="{{ route('logout') }}" class="mx-3 mb-4">
            @csrf
            <x-button type="submit" variant="inverse-outline" class="w-full">Log out</x-button>
        </form>
    </aside>

    <div class="lg:pl-64">
        {{-- Top bar --}}
        <header class="sticky top-0 z-20 border-b border-line bg-cream/95 backdrop-blur supports-[backdrop-filter]:bg-cream/85">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-10">
                <div class="min-w-0">
                    <a href="{{ route('home') }}" class="font-display text-lg font-semibold text-forest lg:hidden">Local Goods</a>
                    <p class="hidden truncate text-sm font-semibold text-muted lg:block">{{ $context }}</p>
                </div>
                <div class="flex min-w-0 items-center gap-3">
                    <div @class(['min-w-0 text-right', 'hidden sm:block' => $role === \App\Enums\Role::Judge])>
                        <p class="truncate text-sm font-semibold"><span class="sr-only">Signed in as </span>@if ($user)<a href="{{ route('account') }}" class="hover:underline underline-offset-4" title="Your account">{{ $userName }}</a>@else{{ $userName }}@endif</p>
                        <x-badge tone="forest" class="mt-0.5"><span class="sr-only">Role: </span>{{ $role->label() }}@if ($viewAs)<span class="hidden sm:inline"> · viewing as {{ $viewAs->label() }}</span>@endif</x-badge>
                    </div>
                    @if ($role === \App\Enums\Role::Judge && ! $votingOnly)
                        <details class="relative">
                            <summary class="cursor-pointer list-none rounded-full border-2 border-forest px-3 py-1.5 text-sm font-semibold whitespace-nowrap text-forest hover:bg-forest hover:text-cream">View as<span aria-hidden="true"> ▾</span></summary>
                            <ul class="absolute right-0 z-40 mt-2 w-56 rounded-xl border border-line bg-paper p-2 shadow-lg">
                                @foreach (\App\Support\ViewAs::ROLES as $option)
                                    <li><a href="{{ route('judge.view-as', $option->value) }}" @if ($viewAs === $option) aria-current="true" @endif
                                           class="block rounded-lg px-3 py-2 text-sm font-semibold hover:bg-cream aria-[current=true]:bg-cream-deep">{{ $option->label() }}</a></li>
                                @endforeach
                                <li class="mt-1 border-t border-line pt-1"><a href="{{ route('judge.view-as') }}" class="block rounded-lg px-3 py-2 text-sm font-semibold hover:bg-cream">Back to judge home</a></li>
                                <li class="lg:hidden"><a href="{{ route('account') }}" class="block rounded-lg px-3 py-2 text-sm font-semibold hover:bg-cream">Your account</a></li>
                            </ul>
                        </details>
                    @endif
                    <span class="hidden size-10 shrink-0 place-items-center rounded-full bg-amber font-display font-semibold text-ink sm:grid" aria-hidden="true">{{ $initials }}</span>
                    <form method="POST" action="{{ route('logout') }}" class="lg:hidden">
                        @csrf
                        <x-button type="submit" variant="ghost" size="sm">Log out</x-button>
                    </form>
                </div>
            </div>
        </header>

        <main id="main" tabindex="-1" class="mx-auto max-w-6xl px-4 pt-6 pb-40 focus:outline-none sm:px-6 lg:px-10 lg:pt-10 lg:pb-12">
            @if ($viewAs)
                <div role="status" class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border-2 border-amber bg-amber/20 px-4 py-3">
                    <p class="font-semibold">You're viewing as {{ $viewAs->label() }}. This is read-only.</p>
                    <a href="{{ route('judge.view-as') }}" class="text-sm font-semibold text-forest underline underline-offset-4 hover:no-underline">Back to judge home</a>
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
    <nav aria-label="App" class="fixed inset-x-0 bottom-0 z-30 border-t border-forest-soft bg-forest pb-[env(safe-area-inset-bottom)] lg:hidden">
        <ul class="grid {{ $tabColumns }}">
            @foreach ($nav as $key => $item)
                <li>
                    <a href="{{ route($item['route']) }}" @if ($active === $key) aria-current="page" @endif
                       class="flex flex-col items-center gap-1 px-1 pt-2.5 pb-2 text-xs font-semibold text-sage hover:text-cream aria-[current=page]:text-amber">
                        <span class="relative">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="size-6" aria-hidden="true"><path d="{{ $item['icon'] }}"/></svg>
                            @if ($key === 'feedback' && $unreadFeedback > 0)
                                <span class="tabular absolute -top-1.5 -right-2.5 rounded-full bg-amber px-1.5 text-[0.65rem] font-semibold text-ink">{{ $unreadFeedback }}<span class="sr-only"> unread</span></span>
                            @endif
                        </span>
                        {{ $item['short'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
</body>
</html>
