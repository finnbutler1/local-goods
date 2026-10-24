{{-- Shared look for the error pages below: what happened, in plain words, and
     where to go next. Kept simple (no database, no session data) so it still
     renders when something is broken. --}}
@extends('layouts.app')

@section('title', $heading.' · Local Goods')

@section('content')
    @php
        // Only 403s carry our own reasons ("Your account is read-only."). Other codes' messages
        // come from the framework and can name internals ("No query results for model …").
        $detail = $code === 403 && isset($exception) ? trim($exception->getMessage()) : '';
        $generic = ['', 'This action is unauthorized.', 'Forbidden'];
        $signedIn = rescue(fn () => auth()->check(), false, false);
    @endphp
    <section aria-labelledby="error-heading" class="mx-auto max-w-2xl px-4 py-16 sm:px-6 sm:py-24">
        <x-eyebrow class="text-accent-ink">Error {{ $code }}</x-eyebrow>
        <h1 id="error-heading" class="mt-2 text-title font-semibold text-primary">{{ $heading }}</h1>
        <p class="mt-4 text-lg text-muted">{{ $explanation }}</p>
        @unless (in_array($detail, $generic, true))
            <p class="mt-3 rounded-xl bg-band p-4 font-semibold">{{ $detail }}</p>
        @endunless
        <div class="mt-8 flex flex-wrap gap-3">
            @if ($signedIn)
                <x-button :href="route('dashboard')">Go to my dashboard</x-button>
            @else
                <x-button :href="route('home')">Go to the home page</x-button>
            @endif
            @if ($back ?? true)
                <x-button variant="secondary" :href="url()->previous() !== url()->current() ? url()->previous() : route('home')">Go back</x-button>
            @endif
        </div>
    </section>
@endsection
