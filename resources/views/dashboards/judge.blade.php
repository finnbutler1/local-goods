<x-app-shell title="Dashboard" active="dashboard" context="CUA Busch School AI Vibe Coding Contest">
    <div class="space-y-8">
        <header>
            <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Judge dashboard</p>
            <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Welcome, {{ Str::before(auth()->user()->name, ' ') }}.</h1>
            <p class="mt-3 max-w-2xl text-lg text-muted">
                Thank you for reviewing Local Goods. Your account can look around but never changes data:
                approving or rejecting redemptions is reserved for BID admins.
            </p>
        </header>

        <x-card as="section" aria-labelledby="empty-heading" class="border-2 border-dashed border-line ring-0! text-center" padding="px-6 py-12">
            <h2 id="empty-heading" class="text-2xl font-semibold text-forest">A read-only tour is on its way</h2>
            <p class="mx-auto mt-2 max-w-md text-muted">
                Read-only views of the BID admin's fraud review and the AI's reasoning will appear here, along with a place to leave feedback.
            </p>
        </x-card>
    </div>
</x-app-shell>
