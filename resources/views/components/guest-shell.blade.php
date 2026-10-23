@props(['title'])

{{-- Signed-out pages (log in, password reset): one centered card on the brand
     background, with the same head, fonts and awning as the public site. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-theme-head />
    <title>{{ $title }} · Local Goods</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col bg-cream font-sans text-ink antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 rounded-full bg-amber px-4 py-2 font-semibold text-ink">Skip to content</a>

    <div class="awning h-2" aria-hidden="true"></div>
    <div class="mx-auto flex w-full max-w-md justify-end px-4 pt-4"><x-theme-toggle /></div>
    <header class="mx-auto w-full max-w-md px-4 pt-4 pb-6 text-center">
        <a href="{{ route('home') }}" class="font-display text-3xl font-semibold tracking-tight text-forest">Local Goods</a>
        <p class="mt-1 text-sm text-muted">Spend local. Prove it.</p>
    </header>

    <main id="main" tabindex="-1" class="mx-auto w-full max-w-md flex-1 px-4 pb-12 focus:outline-none">
        <x-card padding="p-6 sm:p-8">
            {{ $slot }}
        </x-card>
        <p class="mt-6 text-center text-sm">
            <a href="{{ route('home') }}" class="font-semibold text-forest underline decoration-line decoration-2 underline-offset-4 hover:decoration-forest">
                <span aria-hidden="true">←</span> Back to the public site
            </a>
        </p>
    </main>

    <footer class="px-4 pb-8 text-center text-sm text-muted">
        Demonstration project. All businesses, people and transactions are fictional.
    </footer>
</body>
</html>
