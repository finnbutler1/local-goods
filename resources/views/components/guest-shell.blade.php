@props(['title'])

{{-- Signed-out pages (log in, password reset): one narrow column on map paper
     under a station-sign top bar (ADR 0038), with the same head, fonts and
     logo as the public site. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-brand-head />
    <title>{{ $title }} · Local Goods</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col bg-page font-sans text-ink antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 rounded-full bg-accent px-4 py-2 font-semibold text-on-accent">Skip to content</a>

    <header class="mx-auto flex w-full max-w-md items-center justify-between gap-4 px-4 pt-6 pb-8">
        <div>
            <a href="{{ route('home') }}" class="inline-block rounded-lg max-sm:py-0.5" aria-label="Local Goods home"><x-logo class="h-10 w-auto" /></a>
            <p class="mt-1.5 text-sm font-bold">Reroute your lunch.</p>
        </div>
        <x-theme-toggle />
    </header>

    <main id="main" tabindex="-1" class="mx-auto w-full max-w-md flex-1 px-4 pb-12 focus:outline-none">
        <div class="border-t-[6px] border-ink pt-6">
            {{ $slot }}
        </div>
        <p class="mt-8 border-t border-line pt-4 text-sm">
            <a href="{{ route('home') }}" class="tap-target font-semibold text-primary underline decoration-line decoration-2 underline-offset-4 hover:decoration-primary">
                <span aria-hidden="true">←</span> Back to the public site
            </a>
        </p>
    </main>

    <footer class="mx-auto w-full max-w-md px-4 pb-8 text-sm text-muted">
        Demonstration project. All businesses, people and transactions are fictional.
    </footer>
</body>
</html>
