{{-- Feedback asked for from inside a sandbox: it is left from the live demo. --}}
@extends('layouts.app')

@section('title', 'Feedback Is Left From the Live Demo · Local Goods')

@section('content')
    <section aria-labelledby="error-heading" class="mx-auto max-w-2xl px-4 py-16 sm:px-6 sm:py-24">
        <x-station-sign as="p">Feedback</x-station-sign>
        <h1 id="error-heading" class="page-title">Feedback is left from the live demo</h1>
        <p class="page-lede text-muted">
            Your sandbox is a private copy, so nothing sent from it would reach us. To leave feedback, exit the sandbox
            and use the Feedback button on any page of the live demo. Exiting deletes this sandbox.
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
            <form method="POST" action="{{ route('sandbox.exit') }}" data-busy="Leaving…">
                @csrf
                <x-button type="submit">Exit to the live demo</x-button>
            </form>
            <x-button :href="route('sandbox.home')" variant="secondary">Back to my sandbox</x-button>
        </div>
    </section>
@endsection
