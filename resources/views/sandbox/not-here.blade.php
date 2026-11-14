{{-- A page outside the sandbox's money loop (SandboxRoutes), opened from inside one. --}}
@extends('layouts.app')

@section('title', 'Not in the Sandbox · Local Goods')

@section('content')
    <section aria-labelledby="error-heading" class="mx-auto max-w-2xl px-4 py-16 sm:px-6 sm:py-24">
        <x-station-sign as="p">Error 404</x-station-sign>
        <h1 id="error-heading" class="page-title">That page isn't part of the sandbox</h1>
        <p class="page-lede text-muted">
            The sandbox covers buying, giving and spending credits, payouts, impact and fraud review.
            To look at everything else, exit the sandbox first.
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
            <x-button :href="route('sandbox.home')">Back to my sandbox</x-button>
        </div>
    </section>
@endsection
