<x-app-shell title="Dashboard" active="dashboard" context="CUA Busch School AI Vibe Coding Contest">
    <div class="space-y-10">
        <header class="max-w-3xl">
            <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Reviewer home</p>
            <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Welcome, {{ Str::before(auth()->user()->name, ' ') }}.</h1>
            <p class="mt-3 text-lg text-muted">
                Thank you for reviewing Local Goods. Your login is read-only: look at anything, change nothing.
                Use the Feedback button on any page to tell me what works and what doesn't.
            </p>
        </header>

        <section aria-labelledby="about-heading" class="max-w-3xl">
            <h2 id="about-heading" class="text-2xl font-semibold text-forest">What Local Goods is</h2>
            <div class="mt-3 space-y-3 text-lg">
                <p>
                    Local Goods is a workplace perk: employers inside a Business Improvement District buy credits for their staff.
                    Employees can spend those credits only at independent merchants in that district, using their phone as the card.
                    Stripe Connect holds and moves the money, while Local Goods keeps the ledger of who is owed what and enforces the rules.
                    Every redemption is checked by an AI, Redemption Fraud Detection, and anything suspicious is held until a person at the BID decides.
                    This demo covers one district, the Golden Triangle in Washington, DC, and every business, person and transaction in it is fictional.
                </p>
            </div>
        </section>

        <section aria-labelledby="tour-heading">
            <h2 id="tour-heading" class="text-2xl font-semibold text-forest">Guided tour</h2>
            <p class="mt-1 text-muted">Each step opens the screen as that role would see it, read-only.</p>
            <ol class="mt-4 grid gap-3 md:grid-cols-2">
                @foreach ($tour as $i => $step)
                    <x-card as="li" class="flex gap-4">
                        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-forest font-display text-lg font-semibold text-cream" aria-hidden="true">{{ $i + 1 }}</span>
                        <div class="min-w-0">
                            <h3 class="flex flex-wrap items-center gap-2 text-xl font-semibold">
                                <a href="{{ $step['url'] }}" class="text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">
                                    <span class="sr-only">Step {{ $i + 1 }}: </span>{{ $step['title'] }}
                                </a>
                                @unless ($step['available'])
                                    <x-badge tone="neutral">Coming in a later build</x-badge>
                                @endunless
                            </h3>
                            <p class="mt-1 text-muted">{{ $step['look_for'] }}</p>
                        </div>
                    </x-card>
                @endforeach
            </ol>
        </section>
    </div>
</x-app-shell>
