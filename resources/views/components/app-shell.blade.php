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
    $nav = $role->navigation();
    $initials = collect(explode(' ', $userName))
        ->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
    // Literal class names so Tailwind includes them in the build.
    $tabColumns = [1 => 'grid-cols-1', 2 => 'grid-cols-2', 3 => 'grid-cols-3', 4 => 'grid-cols-4'][count($nav)];
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
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
        <div class="m-3 rounded-xl border border-forest-soft p-4 text-sm text-sage">
            <p class="font-semibold text-cream">Demo mode</p>
            <p class="mt-1">All people and transactions are fictional.</p>
            <a href="{{ route('home') }}" class="mt-2 inline-block font-semibold text-cream underline underline-offset-4 hover:no-underline">Back to the public site</a>
        </div>
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
                    <div class="min-w-0 text-right">
                        <p class="truncate text-sm font-semibold"><span class="sr-only">Signed in as </span>{{ $userName }}</p>
                        <x-badge tone="forest" class="mt-0.5"><span class="sr-only">Role: </span>{{ $role->label() }}</x-badge>
                    </div>
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-amber font-display font-semibold text-ink" aria-hidden="true">{{ $initials }}</span>
                    <form method="POST" action="{{ route('logout') }}" class="lg:hidden">
                        @csrf
                        <x-button type="submit" variant="ghost" size="sm">Log out</x-button>
                    </form>
                </div>
            </div>
        </header>

        <main id="main" tabindex="-1" class="mx-auto max-w-6xl px-4 pt-6 pb-28 focus:outline-none sm:px-6 lg:px-10 lg:pt-10 lg:pb-12">
            {{ $slot }}
        </main>

        <footer class="mx-auto hidden max-w-6xl px-4 pb-8 text-sm text-muted sm:px-6 lg:block lg:px-10">
            Demonstration project. All businesses, people and transactions are fictional.
        </footer>
    </div>

    {{-- Mobile bottom tab bar --}}
    <nav aria-label="App" class="fixed inset-x-0 bottom-0 z-30 border-t border-forest-soft bg-forest pb-[env(safe-area-inset-bottom)] lg:hidden">
        <ul class="grid {{ $tabColumns }}">
            @foreach ($nav as $key => $item)
                <li>
                    <a href="{{ route($item['route']) }}" @if ($active === $key) aria-current="page" @endif
                       class="flex flex-col items-center gap-1 px-1 pt-2.5 pb-2 text-xs font-semibold text-sage hover:text-cream aria-[current=page]:text-amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="size-6" aria-hidden="true"><path d="{{ $item['icon'] }}"/></svg>
                        {{ $item['short'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
</body>
</html>
