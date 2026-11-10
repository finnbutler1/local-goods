@extends('layouts.adaptive')

@section('title', 'Fraud check eval · Local Goods')

@section('content')
    <div class="wrap pt-6 pb-6">
        <nav aria-label="Breadcrumb" class="text-sm font-semibold text-primary">
            <a href="{{ route('home') }}" class="tap-target underline decoration-line decoration-2 underline-offset-4 hover:decoration-primary">
                <span aria-hidden="true">←</span> Home
            </a>
        </nav>

        <p class="mt-4 mb-8 border-y border-line py-3 font-semibold">The second AI function, Merchant Eligibility Matching, has its own eval:
            <a href="{{ route('eval.merchants') }}" class="text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover">can the AI tell an independent business from a chain? <span aria-hidden="true">→</span></a>
        </p>

        @include('partials.fraud-eval-results')
    </div>
@endsection
