<x-app-shell title="AI Eval" active="eval">
<div>
    <p class="mb-8 border-y border-line py-3 font-semibold">The second AI function, Merchant Eligibility Matching, has its own eval:
        <a href="{{ route('eval.merchants') }}" class="text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover">can the AI tell an independent business from a chain? <span aria-hidden="true">→</span></a>
    </p>

    @include('partials.fraud-eval-results', ['heading' => 'AI Eval', 'subheading' => 'How often does the AI agree with a person?'])
</div>
</x-app-shell>
