<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-brand-head />
    <meta name="description" content="Local Goods lets employers in Washington, DC's Golden Triangle give staff credits that only work at independent local businesses, so the neighborhood they work in benefits from their daily presence.">
    <title>@yield('title', 'Local Goods')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col bg-page font-sans text-ink antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 rounded-full bg-accent px-4 py-2 font-semibold text-on-accent">Skip to content</a>
    <x-flash />

    <header class="relative z-10">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <a href="{{ route('home') }}" class="shrink-0 rounded-lg" aria-label="Local Goods home">
                <x-logo class="h-8 w-auto sm:h-9" />
            </a>
            <nav aria-label="Site" class="flex items-center gap-1 sm:gap-2">
                <ul class="hidden items-center gap-6 pr-4 text-sm font-semibold text-primary md:flex">
                    <li><a href="{{ route('home') }}#how-it-works" class="underline-offset-4 hover:underline">How it works</a></li>
                    <li><a href="{{ route('home') }}#who-its-for" class="underline-offset-4 hover:underline">Who it's for</a></li>
                    <li><a href="{{ route('home') }}#trust" class="underline-offset-4 hover:underline">Trust &amp; AI</a></li>
                </ul>
                {{-- On phones the three section links live in a menu (ADR 0025). --}}
                <x-menu id="site-menu" class="md:hidden" button-class="grid size-10 place-items-center rounded-full border border-edge bg-surface text-ink hover:border-primary hover:text-primary aria-expanded:border-primary active:translate-y-px">
                    <x-slot:trigger>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="size-5" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                        <span class="sr-only">Site menu</span>
                    </x-slot:trigger>
                    <ul>
                        <li><a href="{{ route('home') }}#how-it-works" class="menu-item">How it works</a></li>
                        <li><a href="{{ route('home') }}#who-its-for" class="menu-item">Who it's for</a></li>
                        <li><a href="{{ route('home') }}#trust" class="menu-item">Trust &amp; AI</a></li>
                    </ul>
                </x-menu>
                <x-theme-toggle />
                @auth
                    <x-button variant="secondary" size="sm" :href="route('dashboard')">My dashboard</x-button>
                @else
                    <x-button variant="secondary" size="sm" :href="route('login')">Log in</x-button>
                @endauth
            </nav>
        </div>
    </header>

    <main id="main" tabindex="-1" class="flex-1 focus:outline-none">
        @yield('content')
    </main>

    <footer class="bg-night text-night-muted">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-[1fr_2fr]">
            <div>
                <p><x-logo :on-dark="true" class="h-9 w-auto" /><span class="sr-only">Local Goods</span></p>
                <p class="mt-2 font-display text-lg font-bold text-on-night">Reroute your lunch.</p>
            </div>
            <div class="space-y-2 text-sm md:text-right">
                <p>Demonstration project for the CUA Busch School AI Vibe Coding Contest.</p>
                <p>All businesses, people, and transactions are fictional.</p>
                <p>Not affiliated with the Golden Triangle BID.</p>
            </div>
        </div>
    </footer>
</body>
</html>
