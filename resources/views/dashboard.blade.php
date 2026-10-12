<x-app-shell title="Dashboard" active="dashboard"
             user-name="Alex Morgan (demo)" role="Employer admin" context="Meridian Policy Group · Golden Triangle BID">
    <div class="space-y-8">
        <header>
            <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Employer dashboard</p>
            <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Welcome back, Alex.</h1>
            <p class="mt-3 max-w-2xl text-lg text-muted">
                This is where your team's neighborhood perk will live: credits funded, credits spent, and the
                local businesses your people discovered.
            </p>
        </header>

        <section aria-labelledby="overview-heading" class="space-y-3">
            <h2 id="overview-heading" class="sr-only">Program overview</h2>
            <dl class="grid gap-3 sm:grid-cols-3">
                @foreach (['Credits funded this month', 'Spent at local merchants', 'Employees active'] as $label)
                    <x-card>
                        <dt class="text-sm font-semibold text-muted">{{ $label }}</dt>
                        <dd class="mt-2 font-display text-3xl font-semibold text-forest">—</dd>
                        <dd class="mt-1 text-sm text-muted">No data yet</dd>
                    </x-card>
                @endforeach
            </dl>
        </section>

        <x-card as="section" aria-labelledby="empty-heading" class="border-2 border-dashed border-line ring-0! text-center" padding="px-6 py-12">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto size-10 text-forest" aria-hidden="true"><path d="M3.75 21h16.5M4.5 21V9.75L12 4.5l7.5 5.25V21M9 21v-6h6v6M3 10.5h18"/></svg>
            <h2 id="empty-heading" class="mt-4 text-2xl font-semibold text-forest">Your program dashboard is on its way</h2>
            <p class="mx-auto mt-2 max-w-md text-muted">
                Funding, participation and spending reports appear here once employer accounts launch.
                In the meantime, try the parts that already work.
            </p>
            <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
                <x-button :href="route('demo.employee')">Open the employee wallet</x-button>
                <x-button variant="secondary" :href="route('demo.bid-admin')">See fraud checks</x-button>
            </div>
        </x-card>
    </div>
</x-app-shell>
