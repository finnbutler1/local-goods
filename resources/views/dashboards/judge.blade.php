<x-app-shell title="Dashboard" active="dashboard" context="CUA Busch School AI Vibe Coding Contest">
    <div class="space-y-10">
        <header class="max-w-3xl">
            <x-eyebrow class="text-accent-ink">Reviewer home</x-eyebrow>
            <h1 class="mt-1 text-title font-semibold text-primary">Welcome, {{ Str::before(auth()->user()->name, ' ') }}.</h1>
            <p class="mt-3 text-lg text-muted">
                Thank you for reviewing Local Goods. Your login is read-only: look at anything, change nothing.
                Use the Feedback button on any page to tell me what works and what doesn't.
            </p>
        </header>

        @if ($adjudication && $adjudication['can_vote'])
            <x-card as="section" tone="night" aria-labelledby="adjudicate-heading" class="max-w-3xl">
                <h2 id="adjudicate-heading" class="text-2xl font-semibold text-on-night">
                    <a href="{{ route('adjudication.show') }}" class="underline decoration-accent decoration-2 underline-offset-4 hover:decoration-on-night">Help check the AI, about 5 minutes</a>
                </h2>
                <p class="mt-2 text-night-muted">
                    @if ($adjudication['submitted'])
                        Thank you: your votes are submitted.
                    @elseif ($adjudication['answered'] > 0)
                        {{ $adjudication['answered'] }} of {{ $adjudication['total'] }} answered. Pick up where you left off, then submit them.
                    @elseif ($adjudication['counted'] > 0)
                        Thank you: your {{ $adjudication['counted'] }} votes count. You can do 10 more if you like.
                    @else
                        Look at {{ $adjudication['total'] }} synthetic redemptions, one at a time, and say whether each looks normal or suspicious. You won't see the AI's answer or ours.
                    @endif
                </p>
            </x-card>
        @elseif ($adjudication)
            <x-card as="section" aria-labelledby="independent-check-heading" class="max-w-3xl border-l-4 border-l-primary">
                <h2 id="independent-check-heading" class="text-2xl font-semibold text-primary">Independent check of the AI</h2>
                <p class="mt-2">
                    Where my hand labels and the AI disagree, invited BID staff and contest judges rate each disputed case blind: they see the redemption's facts, never my label or the AI's call.
                    Cases where we agreed are mixed in as hidden controls, to check how reliable the raters themselves are.
                </p>
                <p class="mt-3 font-semibold">
                    {{ $adjudication['submitted_count'] }} {{ Str::plural('adjudicator', $adjudication['submitted_count']) }} {{ $adjudication['submitted_count'] === 1 ? 'has' : 'have' }} submitted so far.
                    @if ($adjudication['open'])
                        Voting closes {{ $adjudication['ends_at']->format('M j') }}.
                    @else
                        Voting is closed.
                    @endif
                </p>
                <p class="mt-2 text-muted">Voting is limited to invited reviewers, so you can't vote from this login.</p>
                <p class="mt-3">
                    <a href="{{ route('eval') }}#adjudication" class="font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">See the adjudication results on the eval page</a>
                </p>
            </x-card>
        @endif

        <section aria-labelledby="new-heading" class="max-w-3xl">
            <h2 id="new-heading" class="text-2xl font-semibold text-primary">
                What's new {{ $since ? 'since your last visit' : 'lately' }}
            </h2>
            @if ($since)
                <p class="mt-1 text-sm text-muted">Your last visit: {{ $since->setTimezone('America/New_York')->format('M j, g:i A') }}</p>
            @endif
            @if ($entries->isEmpty() && $fixed->isEmpty())
                <p class="mt-3 text-muted">Nothing new since your last visit.</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach ($fixed as $item)
                        <x-card as="li" class="border-l-4 border-l-ok">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold"><x-badge tone="ok">Your feedback: fixed</x-badge> {{ $item->category->label() }}</p>
                            <p class="mt-2">{{ Str::limit($item->comment, 240) }}</p>
                            @if ($item->fixed_commit_sha)
                                <p class="mt-1 text-sm text-muted">Fixed in commit <code class="font-mono">{{ $item->fixed_commit_sha }}</code></p>
                            @endif
                        </x-card>
                    @endforeach
                    @foreach ($entries as $entry)
                        <x-card as="li">
                            <p class="text-sm text-muted">{{ $entry->published_at->setTimezone('America/New_York')->format('M j') }}</p>
                            <h3 class="mt-0.5 text-lg font-semibold">{{ $entry->title }}</h3>
                            <p class="mt-1 whitespace-pre-line">{{ $entry->body }}</p>
                        </x-card>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="about-heading" class="max-w-3xl">
            <h2 id="about-heading" class="text-2xl font-semibold text-primary">Why Local Goods exists</h2>
            <div class="mt-3 space-y-3 text-lg">
                <p>
                    People who work downtown follow the same loop: commute in, sit at a desk, commute out, and their spending goes home with them.
                    Local Goods lets an employer give staff credits that can only be spent at independent businesses in the district around the office,
                    so the neighborhood gains from the people who are there every day.
                </p>
                <p>
                    Employers inside a Business Improvement District buy the credits, and employees spend them using their phone as the card.
                    Payments and merchant payouts run on Stripe Connect in test mode (real Stripe transactions, no real money), and Local Goods tracks every credit in its own append-only ledger and enforces the rules.
                    Every redemption is checked by an AI, Redemption Fraud Detection, and anything suspicious is held until a person at the BID decides.
                    Both AI functions, Redemption Fraud Detection and Merchant Eligibility Matching, assist human decisions; neither makes them.
                    This demo covers one district, the Golden Triangle in Washington, DC, and every business, person and transaction in it is fictional.
                </p>
            </div>
        </section>

        <section aria-labelledby="tour-heading">
            <h2 id="tour-heading" class="text-2xl font-semibold text-primary">Guided tour</h2>
            <p class="mt-1 text-muted">Each step opens the screen as that role would see it, read-only.</p>
            <ol class="mt-4 grid gap-3 md:grid-cols-2">
                @foreach ($tour as $i => $step)
                    <x-card as="li" class="flex gap-4">
                        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-primary font-display text-lg font-semibold text-on-primary" aria-hidden="true">{{ $i + 1 }}</span>
                        <div class="min-w-0">
                            <h3 class="flex flex-wrap items-center gap-2 text-xl font-semibold">
                                <a href="{{ $step['url'] }}" class="tap-target text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">
                                    <span class="sr-only">Step {{ $i + 1 }}: </span>{{ $step['title'] }}
                                </a>
                                @unless ($step['available'])
                                    <x-badge tone="neutral" class="font-sans">Coming in a later build</x-badge>
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
