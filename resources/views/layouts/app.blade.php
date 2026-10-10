<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Local Goods')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-cream text-forest font-sans antialiased flex flex-col">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 bg-amber text-forest px-3 py-2 rounded">Skip to content</a>

    <header class="bg-forest text-cream">
        <div class="mx-auto max-w-5xl px-4 py-3 flex items-center justify-between gap-4">
            <a href="{{ route('home') }}" class="font-semibold tracking-tight text-lg">Local Goods</a>
            <nav aria-label="Demo views" class="flex gap-4 text-sm">
                <a href="{{ route('demo.employee') }}" @if (request()->routeIs('demo.employee')) aria-current="page" @endif
                   class="underline-offset-4 hover:underline aria-[current=page]:underline aria-[current=page]:font-semibold">Employee wallet</a>
                <a href="{{ route('demo.bid-admin') }}" @if (request()->routeIs('demo.bid-admin')) aria-current="page" @endif
                   class="underline-offset-4 hover:underline aria-[current=page]:underline aria-[current=page]:font-semibold">BID admin</a>
            </nav>
        </div>
    </header>

    <main id="main" class="flex-1">
        @yield('content')
    </main>

    <footer class="bg-cream-deep text-muted text-sm">
        <div class="mx-auto max-w-5xl px-4 py-6 space-y-1">
            <p>A demonstration project for the CUA Busch School AI Vibe Coding Contest.</p>
            <p>All businesses, people, and transactions are fictional.</p>
            <p>Not affiliated with the Golden Triangle Business Improvement District.</p>
        </div>
    </footer>
</body>
</html>
