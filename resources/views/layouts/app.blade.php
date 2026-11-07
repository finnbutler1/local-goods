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

    {{-- The header is a station sign: dark in both modes (ADR 0038). --}}
    <header class="relative z-10 bg-night text-on-night">
        <div class="wrap flex min-h-18 items-center justify-between gap-4 py-3">
            <a href="{{ route('home') }}" class="shrink-0 rounded-lg max-sm:grid max-sm:min-h-11 max-sm:min-w-11 max-sm:place-items-center" aria-label="Local Goods home">
                {{-- The mark alone on phones, so the header fits 320px. --}}
                <x-logo :on-dark="true" :mark-only="true" class="h-8 w-auto sm:hidden" />
                <x-logo :on-dark="true" class="hidden h-8 w-auto sm:block" />
            </a>
            <nav aria-label="Site" class="flex items-center gap-1 sm:gap-2">
                <ul class="hidden items-center gap-1 pr-3 text-[0.9375rem] font-semibold md:flex">
                    <li><a href="{{ route('home') }}#how-it-works" class="inline-flex min-h-11 items-center rounded-lg px-3 text-on-night decoration-2 underline-offset-4 hover:underline">How it works</a></li>
                    <li><a href="{{ route('home') }}#why-it-matters" class="inline-flex min-h-11 items-center rounded-lg px-3 text-on-night decoration-2 underline-offset-4 hover:underline">Who it's for</a></li>
                    <li><a href="{{ route('home') }}#trust" class="inline-flex min-h-11 items-center rounded-lg px-3 text-on-night decoration-2 underline-offset-4 hover:underline">Trust &amp; AI</a></li>
                </ul>
                {{-- On phones the three section links live in a menu (ADR 0025). --}}
                <x-menu id="site-menu" class="md:hidden" button-class="grid size-11 place-items-center rounded-lg border-2 border-night-muted text-on-night hover:bg-night-soft aria-expanded:bg-night-soft active:translate-y-px">
                    <x-slot:trigger>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="size-5" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                        <span class="sr-only">Site menu</span>
                    </x-slot:trigger>
                    <ul>
                        <li><a href="{{ route('home') }}#how-it-works" class="menu-item">How it works</a></li>
                        <li><a href="{{ route('home') }}#why-it-matters" class="menu-item">Who it's for</a></li>
                        <li><a href="{{ route('home') }}#trust" class="menu-item">Trust &amp; AI</a></li>
                    </ul>
                </x-menu>
                <x-theme-toggle :on-dark="true" />
                @auth
                    <x-button variant="inverse-outline" size="sm" class="min-h-11" :href="route('dashboard')">My dashboard</x-button>
                @else
                    <x-button variant="inverse-outline" size="sm" class="min-h-11" :href="route('login')">Log in</x-button>
                @endauth
            </nav>
        </div>
    </header>

    <main id="main" tabindex="-1" class="flex-1 focus:outline-none">
        @yield('content')
    </main>

    <footer class="border-t-[6px] border-primary bg-night pb-[env(safe-area-inset-bottom)] text-night-muted">
        <div class="wrap grid gap-8 py-12 md:grid-cols-[1fr_2fr]">
            <div>
                <p><x-logo :on-dark="true" class="h-8 w-auto" /><span class="sr-only">Local Goods</span></p>
                <p class="mt-3 font-display text-lg font-extrabold text-on-night">Reroute your lunch.</p>
            </div>
            <div class="space-y-2 text-sm md:pt-1">
                <p>Demonstration project for the CUA Busch School AI Vibe Coding Contest.</p>
                <p>All businesses, people, and transactions are fictional.</p>
                <p>Not affiliated with the Golden Triangle BID.</p>
            </div>
        </div>
    </footer>
</body>
</html>
