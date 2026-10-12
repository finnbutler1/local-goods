<x-app-shell title="Dashboard" active="dashboard" context="Farragut Grind · Golden Triangle BID">
    <div class="space-y-8">
        <header>
            <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Merchant dashboard</p>
            <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Welcome back, {{ Str::before(auth()->user()->name, ' ') }}.</h1>
            <p class="mt-3 max-w-2xl text-lg text-muted">
                See what Local Goods customers spent at your business and when your payouts arrive.
            </p>
        </header>

        <section aria-labelledby="overview-heading">
            <h2 id="overview-heading" class="sr-only">Sales overview</h2>
            <dl class="grid gap-3 sm:grid-cols-3">
                @foreach (['Local Goods sales this month', 'Next payout', 'Repeat customers'] as $label)
                    <x-card>
                        <dt class="text-sm font-semibold text-muted">{{ $label }}</dt>
                        <dd class="mt-2 font-display text-3xl font-semibold text-forest">—</dd>
                        <dd class="mt-1 text-sm text-muted">No data yet</dd>
                    </x-card>
                @endforeach
            </dl>
        </section>

        <x-card as="section" aria-labelledby="empty-heading" class="border-2 border-dashed border-line ring-0! text-center" padding="px-6 py-12">
            <h2 id="empty-heading" class="text-2xl font-semibold text-forest">Your sales and payouts are on their way</h2>
            <p class="mx-auto mt-2 max-w-md text-muted">
                Redemptions at your business and Stripe payouts will be listed here once merchant accounts launch.
            </p>
        </x-card>
    </div>
</x-app-shell>
