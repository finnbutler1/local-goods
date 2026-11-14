@php
    $link = 'font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover';
@endphp

<x-app-shell title="Dashboard" active="dashboard" context="CUA Busch School AI Vibe Coding Contest">
    <div class="grid-12 gap-y-14">
        <header class="col-span-12">
            <x-station-sign as="p">Reviewer home</x-station-sign>
            <h1 class="page-title">{{ auth()->user()->isPublicReviewer() ? 'Welcome' : 'Welcome, '.Str::before(auth()->user()->name, ' ').'.' }}</h1>
            <div class="grid-12">
                <div class="col-span-12 lg:col-span-8">
                    @include('partials.one-minute-summary', ['class' => 'page-lede'])
                    <p class="mt-4 max-w-[42rem] text-muted">
                        Thank you for reviewing Local Goods. Your login is read-only, so you can open any page but can't change anything.
                        Use the Feedback button on any page to tell me what works and what doesn't.
                    </p>
                    @if (\App\Services\Sandbox\Sandbox::enabled())
                        {{-- ADR 0045: a private copy where money really moves; this login stays read-only. --}}
                        <p class="mt-4 max-w-[42rem]">Want to move money yourself? Get a private copy of the demo, separate from the live one.</p>
                        <x-button variant="accent" class="mt-3" :href="route('sandbox.show')">Try it yourself</x-button>
                    @endif
                </div>
            </div>
        </header>

        <section aria-labelledby="money-heading" class="col-span-12 md:col-span-6 lg:col-span-4">
            <x-station-sign heading-id="money-heading"><a href="{{ route('money') }}" class="text-on-night underline decoration-night-accent decoration-2 underline-offset-4 hover:decoration-night-primary">Where the Money Is</a></x-station-sign>
            <p class="mt-3 max-w-[38rem]">
                Employers' money held for their staff is kept apart from anything Local Goods earns. A check runs over the whole ledger history
                to test whether every promise has always had money behind it.
            </p>
        </section>

        <section aria-labelledby="model-heading" class="col-span-12 md:col-span-6 lg:col-span-4">
            <x-station-sign heading-id="model-heading"><a href="{{ route('business-model') }}" class="text-on-night underline decoration-night-accent decoration-2 underline-offset-4 hover:decoration-night-primary">Business Model</a></x-station-sign>
            <p class="mt-3 max-w-[38rem]">
                This page shows how Local Goods could pay for itself in the Golden Triangle, across DC and nationally. It includes a projected
                P&amp;L, what one district needs to break even, and every assumption with its source. A projection, not results.
            </p>
        </section>

        <section aria-labelledby="security-heading" class="col-span-12 md:col-span-6 lg:col-span-4">
            <x-station-sign heading-id="security-heading"><a href="{{ route('security') }}" class="text-on-night underline decoration-night-accent decoration-2 underline-offset-4 hover:decoration-night-primary">Security &amp; Trust</a></x-station-sign>
            <p class="mt-3 max-w-[38rem]">
                What protects the money, access, data and AI decisions, in plain language, with the file behind each protection.
                It also lists what isn't done yet.
            </p>
        </section>

        <section aria-labelledby="coming-next-heading" class="col-span-12 md:col-span-6 lg:col-span-4">
            <x-station-sign heading-id="coming-next-heading"><a href="{{ route('coming-next') }}" class="text-on-night underline decoration-night-accent decoration-2 underline-offset-4 hover:decoration-night-primary">Coming next</a></x-station-sign>
            <p class="mt-3 max-w-[38rem]">
                Ideas for where Local Goods could go, drawn as sketches. Each is labelled "Concept, not built" and says what it would take.
            </p>
        </section>

        <section aria-labelledby="new-heading" class="col-span-12 lg:col-span-5">
            <x-station-sign heading-id="new-heading">What's new {{ $since ? 'since your last visit' : 'lately' }}</x-station-sign>
            @if ($since)
                <p class="mt-4 text-sm text-muted">Your last visit: <span class="ledger-num">{{ $since->setTimezone('America/New_York')->format('M j, g:i A') }}</span></p>
            @endif
            @if ($entries->isEmpty() && $fixed->isEmpty())
                <p class="mt-4 text-muted">Nothing new since your last visit.</p>
            @else
                <ul class="ruled-rows mt-5">
                    @foreach ($fixed as $item)
                        <li>
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold"><x-badge tone="ok">Your feedback: fixed</x-badge> {{ $item->category->label() }}</p>
                            <p class="mt-2">{{ Str::limit($item->comment, 240) }}</p>
                            @if ($item->fixed_commit_sha)
                                <p class="mt-1 text-sm text-muted">Fixed in commit <code class="font-mono">{{ $item->fixed_commit_sha }}</code></p>
                            @endif
                        </li>
                    @endforeach
                    @foreach ($entries as $entry)
                        <li>
                            <p class="ledger-num text-sm text-muted">{{ $entry->published_at->setTimezone('America/New_York')->format('M j') }}</p>
                            <h3 class="mt-0.5 text-lg leading-snug font-bold">{{ $entry->title }}</h3>
                            <p class="mt-1 whitespace-pre-line">{{ $entry->body }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="about-heading" class="col-span-12 lg:col-span-7">
            <x-station-sign heading-id="about-heading">Why Local Goods exists</x-station-sign>
            <div class="mt-6 max-w-[42rem] space-y-4 text-lg leading-relaxed">
                <p>
                    People who work downtown follow the same loop: commute in, sit at a desk, commute out, and their spending goes home with them.
                    Local Goods lets an employer give staff credits that can only be spent at independent businesses in the district around the office.
                    The neighborhood then gains from the people who are there every day.
                </p>
                <p>
                    Employers inside a Business Improvement District buy the credits, and employees spend them using their phone as the card.
                    Payments and merchant payouts run on Stripe Connect in test mode (real Stripe transactions, no real money). Local Goods tracks every credit in its own append-only ledger and enforces the rules.
                    Every redemption is checked by an AI, Redemption Fraud Detection, and anything suspicious is held until a person at the BID decides.
                    Both AI functions, Redemption Fraud Detection and Merchant Eligibility Matching, flag and explain; people decide every held redemption and every new merchant.
                    This demo covers one district, the Golden Triangle in Washington, DC, and every business, person and transaction in it is fictional.
                </p>
            </div>
        </section>

        <section aria-labelledby="tour-heading" class="col-span-12">
            <x-station-sign heading-id="tour-heading" :dots="count($tour)">Guided tour</x-station-sign>
            <p class="mt-4 text-muted">Each step opens the screen as that role would see it, read-only.</p>
            <ol class="tour-line mt-5">
                @foreach ($tour as $i => $step)
                    <li>
                        <x-stop-number :n="$i + 1" />
                        <div class="min-w-0 lg:grid lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-x-6">
                            <h3 class="flex flex-wrap items-center gap-2 self-start text-lg leading-snug font-bold">
                                <a href="{{ $step['url'] }}" class="tap-target text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover">
                                    <span class="sr-only">Step {{ $i + 1 }}: </span>{{ $step['title'] }}
                                </a>
                                @unless ($step['available'])
                                    <x-badge tone="neutral" class="font-sans">Coming in a later build</x-badge>
                                @endunless
                            </h3>
                            <p class="mt-0.5 text-muted lg:mt-0 lg:pt-0.5">{{ $step['look_for'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
</x-app-shell>
