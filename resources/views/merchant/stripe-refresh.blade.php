<x-app-shell title="Continue Stripe onboarding" active="dashboard" context="Merchant · Golden Triangle BID">
    <x-card as="section" aria-labelledby="refresh-heading" class="max-w-2xl">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 id="refresh-heading" class="text-2xl font-semibold text-primary">That Stripe link has expired</h1>
            <x-money-mode />
        </div>
        <p class="mt-2 text-muted">Stripe onboarding links only work for a few minutes. Start a fresh one to carry on where you left off.</p>
        @if ($canOnboard)
            <form method="POST" action="{{ route('merchant.stripe.onboard') }}" data-busy="Opening Stripe…" class="mt-4">
                @csrf
                <x-button type="submit" variant="accent">Continue Stripe onboarding (test mode)</x-button>
            </form>
        @else
            <p class="mt-4 text-sm text-muted">Read-only: judges can't onboard.</p>
        @endif
        <p class="mt-4"><a href="{{ route('merchant.dashboard') }}" class="tap-target font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Back to the merchant dashboard</a></p>
    </x-card>
</x-app-shell>
