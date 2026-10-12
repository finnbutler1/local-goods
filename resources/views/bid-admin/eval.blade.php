<x-app-shell title="Fraud check eval" active="eval">
<div class="space-y-10">
    <nav aria-label="Demo pages" class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm font-semibold text-forest">
        <a href="{{ route('bid-admin.dashboard') }}" class="underline decoration-line decoration-2 underline-offset-4 hover:decoration-forest">
            <span aria-hidden="true">←</span> Back to fraud review
        </a>
    </nav>

    @include('partials.fraud-eval-results')
</div>
</x-app-shell>
