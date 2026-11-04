<x-app-shell title="AI Eval" active="eval">
<div class="space-y-10">
    <x-card tone="tint" class="max-w-3xl">
        <p class="font-semibold">The second AI function, Merchant Eligibility Matching, has its own eval:
            <a href="{{ route('eval.merchants') }}" class="text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">can the AI tell an independent business from a chain? <span aria-hidden="true">→</span></a>
        </p>
    </x-card>

    @include('partials.fraud-eval-results', ['heading' => 'AI Eval', 'subheading' => 'How often does the AI agree with a person?'])
</div>
</x-app-shell>
