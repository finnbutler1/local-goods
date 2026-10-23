<x-app-shell title="Fraud check eval" active="eval">
<div class="space-y-10">
    <nav aria-label="Demo pages" class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm font-semibold text-primary">
        <a href="{{ route('bid-admin.dashboard') }}" class="underline decoration-line decoration-2 underline-offset-4 hover:decoration-primary">
            <span aria-hidden="true">←</span> Back to fraud review
        </a>
    </nav>

    <x-card tone="tint" class="max-w-3xl">
        <p class="font-semibold">The second AI function, Merchant Eligibility Matching, has its own eval:
            <a href="{{ route('eval.merchants') }}" class="text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">can the AI tell an independent business from a chain? <span aria-hidden="true">→</span></a>
        </p>
    </x-card>

    @include('partials.fraud-eval-results')
</div>
</x-app-shell>
