<x-app-shell title="Demo Tools" active="demo-tools">
<div class="max-w-2xl space-y-6">
    <header>
        <x-eyebrow class="text-accent-ink">Your account</x-eyebrow>
        <h1 class="mt-1 text-title font-semibold text-primary">Demo Tools</h1>
        <p class="mt-3 text-lg text-muted">For demonstrations: fills in a payment on your wallet that the fraud check is designed to hold. Confirm it there, then see a person decide it in Fraud Review.</p>
    </header>

    <x-card as="section" aria-labelledby="demo-tools-heading">
        <h2 id="demo-tools-heading" class="sr-only">Demo controls</h2>
        <x-button variant="danger" size="sm" :href="route('employee.wallet', ['suspicious' => 1]).'#redeem'" class="w-full">
            Try a suspicious redemption
        </x-button>
    </x-card>
</div>
</x-app-shell>
