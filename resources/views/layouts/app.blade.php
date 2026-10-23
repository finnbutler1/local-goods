<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1f3d2b">
    <meta name="description" content="Local Goods lets employers in Washington, DC's Golden Triangle give staff credits that only work at independent local businesses, so the neighborhood they work in benefits from their daily presence.">
    <title>@yield('title', 'Local Goods')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col bg-cream font-sans text-ink antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 rounded-full bg-amber px-4 py-2 font-semibold text-ink">Skip to content</a>
    <x-flash />

    <header class="relative z-10">
        <div class="awning h-2" aria-hidden="true"></div>
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <a href="{{ route('home') }}" class="flex items-baseline gap-2 font-display text-2xl font-semibold tracking-tight text-forest">
                Local Goods
            </a>
            <nav aria-label="Site" class="flex items-center gap-1 sm:gap-2">
                <ul class="hidden items-center gap-6 pr-4 text-sm font-semibold text-forest md:flex">
                    <li><a href="{{ route('home') }}#how-it-works" class="underline-offset-4 hover:underline">How it works</a></li>
                    <li><a href="{{ route('home') }}#who-its-for" class="underline-offset-4 hover:underline">Who it's for</a></li>
                    <li><a href="{{ route('home') }}#trust" class="underline-offset-4 hover:underline">Trust &amp; AI</a></li>
                </ul>
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

    <footer class="bg-forest text-sage">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-[1fr_2fr]">
            <div>
                <p class="font-display text-2xl font-semibold text-cream">Local Goods</p>
                <p class="mt-1">Spend local. Prove it.</p>
            </div>
            <div class="space-y-2 text-sm md:text-right">
                <p>Demonstration project for the CUA Busch School AI Vibe Coding Contest.</p>
                <p>All businesses, people, and transactions are fictional.</p>
                <p>Not affiliated with the Golden Triangle BID.</p>
            </div>
        </div>
        <div class="awning h-2 opacity-40" aria-hidden="true"></div>
    </footer>
</body>
</html>
