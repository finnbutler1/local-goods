@extends('layouts.app')

@section('title', 'Fraud check eval · Local Goods')

@section('content')
    <div class="mx-auto max-w-6xl space-y-10 px-4 py-12 sm:px-6">
        <nav aria-label="Breadcrumb" class="text-sm font-semibold text-forest">
            <a href="{{ route('home') }}" class="underline decoration-line decoration-2 underline-offset-4 hover:decoration-forest">
                <span aria-hidden="true">←</span> Home
            </a>
        </nav>

        <x-card tone="tint" class="max-w-3xl">
            <p class="font-semibold">The second AI function, Merchant Eligibility Matching, has its own eval:
                <a href="{{ route('eval.merchants') }}" class="text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">can the AI tell an independent business from a chain? <span aria-hidden="true">→</span></a>
            </p>
        </x-card>

        @include('partials.fraud-eval-results')
    </div>
@endsection
