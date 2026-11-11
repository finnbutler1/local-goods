<x-app-shell title="Demo Tools" active="demo-tools">
<div class="max-w-2xl space-y-6">
    <header>
        <x-station-sign as="p">Your account</x-station-sign>
        <h1 class="page-title">Demo Tools</h1>
        <p class="page-lede text-muted">For demonstrations: fills in a payment on your wallet that the fraud check is designed to hold. Confirm it there, then see a person decide it in Fraud Review.</p>
    </header>

    <x-card as="section" aria-labelledby="demo-tools-heading">
        <h2 id="demo-tools-heading" class="sr-only">Demo controls</h2>
        <x-button variant="danger" size="sm" :href="route('employee.wallet', ['suspicious' => 1]).'#redeem'" class="w-full">
            Try a suspicious redemption
        </x-button>
    </x-card>
</div>
</x-app-shell>
