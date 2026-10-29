@extends('layouts.app')

@section('title', 'Local Goods: You work here. Spend here.')

@section('content')
    {{-- Hero --}}
    <section aria-labelledby="hero-heading" class="mx-auto grid overflow-x-clip max-w-6xl items-center gap-12 px-4 pt-10 pb-20 sm:px-6 lg:grid-cols-[1.15fr_1fr] lg:pt-16 lg:pb-28">
        <div>
            <x-eyebrow class="text-accent-ink">For employers in the Golden Triangle · Washington, DC</x-eyebrow>
            <h1 id="hero-heading" class="mt-4 text-[clamp(3rem,0.5rem+12.5vw,3.75rem)] leading-[0.95] font-semibold text-primary sm:text-7xl lg:text-8xl">
                You work here.<br>
                <span class="relative inline-block">
                    Spend here.
                    <span class="absolute inset-x-0 -bottom-1 h-3 rounded-full bg-accent/70 sm:h-4" aria-hidden="true"></span>
                </span>
            </h1>
            <p class="mt-8 max-w-xl text-lg leading-relaxed text-ink sm:text-xl">
                At 6 p.m., downtown's workers head home, and their spending goes with them. Local Goods gives employees
                credits that only work at independent local businesses, so the district your company calls home gets livelier.
            </p>
            {{-- The commute-desk-commute loop, rerouted through one local stop. --}}
            <ol class="route-line mt-8 max-w-md" aria-label="A workday, rerouted">
                <li>Home</li>
                <li>Desk</li>
                <li class="route-line-local"><strong class="block font-semibold">Farragut Grind</strong> <span class="font-semibold text-accent-ink">local stop</span></li>
                <li>Home</li>
            </ol>
            <div class="mt-10 flex flex-col gap-3 sm:flex-row">
                <x-button variant="accent" size="lg" href="#request-demo">Request a demo</x-button>
                <x-button variant="secondary" size="lg" :href="route('login')">Log in</x-button>
            </div>
            <p class="mt-4 text-sm text-muted">A working prototype: payments run on Stripe in test mode, and every person and business in the demo is fictional.</p>
        </div>

        {{-- Illustration of the wallet, built in HTML so it stays sharp and needs no image file. --}}
        <div class="relative mx-auto w-full max-w-sm" role="img"
             aria-label="Example employee wallet: $120.00 available, with a $12.90 lunch at Press Room Deli approved after an AI fraud check.">
            <div class="absolute -inset-4 -z-10 rotate-3 rounded-[2.5rem] bg-band" aria-hidden="true"></div>
            <div aria-hidden="true" class="overflow-hidden rounded-[2rem] bg-surface shadow-[0_30px_60px_-30px_rgb(18_24_32/0.55)] ring-1 ring-line">
                <div class="relative bg-night px-6 pt-7 pb-8 text-on-night">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-semibold uppercase tracking-widest text-night-muted">Available</span>
                        <span class="font-display font-semibold">Local Goods</span>
                    </div>
                    <p class="tabular mt-3 font-display text-6xl font-semibold text-night-accent">$120.00</p>
                    <p class="mt-2 text-sm text-night-muted">$150.00 added monthly by your employer</p>
                </div>
                <ul class="divide-y divide-line/70 px-6 py-2 text-sm">
                    @foreach ([['Press Room Deli', 'Lunch · 12:15 PM', '$12.90'], ['Farragut Grind', 'Coffee · 8:55 AM', '$7.25'], ['Page & Quill Books', 'Books · 1:05 PM', '$13.00']] as [$name, $when, $amount])
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div>
                                <p class="font-semibold">{{ $name }}</p>
                                <p class="text-muted">{{ $when }}</p>
                            </div>
                            <div class="text-right">
                                <p class="tabular font-semibold">{{ $amount }}</p>
                                <x-badge tone="ok" class="mt-0.5"><span class="size-1.5 rounded-full bg-ok"></span>Approved</x-badge>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div aria-hidden="true" class="absolute -bottom-5 -left-1 flex items-center gap-2 rounded-full bg-primary py-2 pr-4 pl-2 text-sm font-semibold text-on-primary shadow-lg sm:-left-10">
                <span class="grid size-7 place-items-center rounded-full bg-accent text-on-accent">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="size-4"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                </span>
                AI fraud check passed
            </div>
            <p aria-hidden="true" class="absolute -top-3 right-4 rounded-full bg-accent px-3 py-1 text-xs font-semibold text-on-accent">Demo</p>
        </div>
    </section>

    {{-- The problem: the commute-desk-commute loop, named without blaming anyone --}}
    <section id="the-problem" aria-labelledby="problem-heading" class="mx-auto max-w-6xl scroll-mt-6 px-4 pb-20 sm:px-6">
        <div class="max-w-3xl border-l-4 border-accent pl-6">
            <x-eyebrow class="text-accent-ink">The problem</x-eyebrow>
            <h2 id="problem-heading" class="mt-2 text-4xl font-semibold text-primary sm:text-5xl">The commute-desk-commute loop</h2>
            <p class="mt-5 text-lg leading-relaxed text-ink">
                Thousands of people work in the Golden Triangle every weekday. Most follow the same loop: commute in, sit at a desk,
                commute out. That isn't a failing of the people doing it; it's how office work is set up. But it means their spending,
                and the life they could bring to the streets around them, goes home with them.
            </p>
        </div>
    </section>

    {{-- How it works --}}
    <section id="how-it-works" aria-labelledby="how-heading" class="scroll-mt-6 border-y border-line bg-surface">
        <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
            <x-eyebrow class="text-accent-ink">How it works</x-eyebrow>
            <h2 id="how-heading" class="mt-2 max-w-2xl text-4xl font-semibold text-primary sm:text-5xl">Three steps from payroll perk to a busier block.</h2>
            <div class="relative mt-14">
            {{-- Runs from the centre of circle 1 to the centre of circle 3 (circles are 3.5rem, column gap 2rem),
                 so the circles cover both ends exactly. Keep in sync with size-14 and md:gap-8 below. --}}
            <div class="ledger-rule absolute top-[1.625rem] left-7 hidden h-1 w-[calc((100%_-_4rem)*2/3_+_4rem)] md:block" aria-hidden="true"></div>
            <ol class="relative grid gap-12 md:grid-cols-3 md:gap-8">
                @foreach ([
                    ['Your company buys credits', 'Fund a monthly allowance for each employee. One invoice, no cards to issue, nothing for staff to sign up for beyond an app.'],
                    ['Employees spend them nearby', 'Credits work only at independent restaurants, shops, salons and gyms inside the district. The phone is the card.'],
                    ['The money stays in the neighborhood', 'Every redemption is checked for abuse before a merchant is paid. Payments and merchant payouts run on Stripe in test mode: real Stripe transactions, no real money.'],
                ] as $i => [$title, $body])
                    <li class="relative">
                        <span class="relative grid size-14 place-items-center rounded-full bg-primary font-display text-2xl font-semibold text-on-primary" aria-hidden="true">{{ $i + 1 }}</span>
                        <h3 class="mt-5 text-2xl font-semibold text-primary"><span class="sr-only">Step {{ $i + 1 }}: </span>{{ $title }}</h3>
                        <p class="mt-2 text-muted">{{ $body }}</p>
                    </li>
                @endforeach
            </ol>
            </div>
        </div>
    </section>

    {{-- Why it matters: the district first, then everyone else who gains (ADR 0024) --}}
    <section id="why-it-matters" aria-labelledby="why-heading" class="mx-auto max-w-6xl scroll-mt-6 px-4 py-20 sm:px-6">
        <x-eyebrow class="text-accent-ink">Why it matters</x-eyebrow>
        <h2 id="why-heading" class="mt-2 max-w-2xl text-4xl font-semibold text-primary sm:text-5xl">Everyone gains. The district gains most.</h2>

        <div class="mt-12 grid gap-4 lg:grid-cols-4">
            <x-card as="article" aria-labelledby="gain-district" tone="tint" class="lg:col-span-4" padding="p-8 lg:p-10">
                <p class="text-sm font-semibold uppercase tracking-widest text-muted">Most of all</p>
                <h3 id="gain-district" class="mt-2 text-4xl font-semibold text-primary">The district</h3>
                <p class="mt-3 max-w-3xl text-lg text-ink">
                    More foot traffic and more spending at the independent shops, restaurants and services that give the Golden Triangle
                    its character. A livelier district is good for everyone who works, lives or does business in it.
                </p>
            </x-card>

            <article aria-labelledby="gain-employers" class="relative flex flex-col overflow-hidden rounded-3xl bg-night p-8 text-on-night lg:col-span-2 lg:row-span-2 lg:p-10">
                <x-badge tone="accent" class="self-start">For HR teams</x-badge>
                <h3 id="gain-employers" class="mt-5 text-4xl font-semibold">Employers</h3>
                <p class="mt-3 text-lg text-night-muted">A benefit your staff actually use, because it shows up at lunch, not in a portal.</p>
                <ul class="mt-8 space-y-4">
                    @foreach ([
                        'A practical way to improve the streets around your own office.',
                        'A livelier neighborhood can make an office easier to recruit to, easier to stay at and more worth coming in to; a pilot would measure how much.',
                        'Participation and spending reports you can bring to leadership.',
                        'No cards to issue: the employee\'s phone is the card.',
                    ] as $point)
                        <li class="flex gap-3">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 size-5 shrink-0 text-night-accent" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-auto pt-10">
                    <x-button variant="accent" href="#request-demo">Request a demo for your team</x-button>
                </div>
            </article>

            @foreach ([
                ['gain-merchants', 'For independents', 'Merchants', 'A large, steady workforce audience on your doorstep, and a reason for them to come back. No new hardware: customers show the app at the counter. Payments and merchant payouts run on Stripe in test mode: real Stripe transactions, no real money.', [['apply', 'Apply to join']]],
                ['gain-employees', 'For staff', 'Employees', 'A perk that makes the workday better: lunch, coffee, errands. And a reason to discover the neighborhood you already spend most of your week in.', []],
                ['gain-bid', 'For the Golden Triangle', 'The BID', 'A measurable, privacy-respecting way to support the district\'s independent businesses. Spending and participation are counted from the ledger; employees are never named.', [['who-does-what', 'Who does what'], ['district', 'The district map']]],
            ] as [$id, $eyebrow, $title, $body, $links])
                <x-card as="article" aria-labelledby="{{ $id }}" class="lg:col-span-2" padding="p-8">
                    <p class="text-sm font-semibold uppercase tracking-widest text-muted">{{ $eyebrow }}</p>
                    <h3 id="{{ $id }}" class="mt-2 text-3xl font-semibold text-primary">{{ $title }}</h3>
                    <p class="mt-3 text-muted">{{ $body }}</p>
                    @if ($links !== [])
                        <p class="mt-4 flex flex-wrap gap-x-5 gap-y-1">
                            @foreach ($links as [$route, $text])
                                <a href="{{ route($route) }}" class="tap-target font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">{{ $text }} <span aria-hidden="true">→</span></a>
                            @endforeach
                        </p>
                    @endif
                </x-card>
            @endforeach
        </div>

        {{-- Supporting evidence, below the mission, never the hero. Always with its label and source. --}}
        <section aria-label="Outside estimate of local recirculation" class="mt-8 rounded-3xl border-2 border-dashed border-muted bg-band p-6 sm:p-8">
            @include('partials.recirculation-evidence')
        </section>
    </section>

    {{-- Trust and AI --}}
    <section id="trust" aria-labelledby="trust-heading" class="scroll-mt-6 bg-night text-on-night">
        <div class="mx-auto grid max-w-6xl gap-12 px-4 py-20 sm:px-6 lg:grid-cols-2 lg:gap-16">
            <div>
                <x-eyebrow class="text-night-accent">Trust and AI</x-eyebrow>
                <h2 id="trust-heading" class="mt-2 text-4xl font-semibold sm:text-5xl">Every redemption is checked before anyone is paid.</h2>
                <ul class="mt-8 space-y-5">
                    @foreach ([
                        ['Redemption Fraud Detection', 'scores every redemption for abuse, such as turning credits into cash, and explains its reasoning in plain language.'],
                        ['It fails closed.', 'If the AI check errors or gives an unusable answer, the redemption is held. It is never waved through.'],
                        ['The AI assists; people decide.', 'Redemption Fraud Detection and Merchant Eligibility Matching flag and explain. A held redemption is never approved automatically; it waits for review by the BID, and a person approves every new merchant.'],
                        ['No names go to the AI.', 'It sees only anonymous signals, like amount, time of day and visit history.'],
                        ['Shops see initials, not names.', 'A shop needs to know a payment is real, not who the person is.'],
                        ['Held payments use neutral words.', 'A held payment at a counter says it is held, with no fraud words, so nobody is accused.'],
                        ['Honesty labels stay on phones.', 'Demo data, test mode and "not affiliated" notices are never hidden on a small screen.'],
                    ] as [$lead, $rest])
                        <li class="flex gap-3">
                            <span class="mt-2 size-2 shrink-0 rounded-full bg-accent" aria-hidden="true"></span>
                            <p class="text-night-muted"><strong class="font-semibold text-on-night">{{ $lead }}</strong> {{ $rest }}</p>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-6">
                    <a href="{{ route('who-does-what') }}#not-built" class="tap-target font-semibold text-on-night underline decoration-night-accent decoration-2 underline-offset-4 hover:decoration-on-night">What we chose not to build, and why <span aria-hidden="true">→</span></a>
                </p>
            </div>

            <figure class="self-center rounded-3xl bg-page p-8 text-ink sm:p-10">
                <x-eyebrow class="text-accent-ink">Measured, not promised</x-eyebrow>
                @if ($evalHeadline)
                    <blockquote class="mt-4">
                        <p class="font-display text-3xl leading-tight font-semibold text-primary sm:text-4xl">{{ $evalHeadline['text'] }}</p>
                    </blockquote>
                    <figcaption class="mt-5 space-y-2 text-muted">
                        <p>Compared with a person's labels on hand-labeled test redemptions the AI had never seen.</p>
                        <p class="text-sm">{{ $evalHeadline['sample'] }}</p>
                    </figcaption>
                @else
                    <p class="mt-4 font-display text-3xl font-semibold text-primary">Eval results not yet published.</p>
                    <figcaption class="mt-5 text-muted">We publish how often the AI agrees with a person once the eval has been run.</figcaption>
                @endif
                <div class="mt-8">
                    <x-button :href="route('eval')">See the full eval results</x-button>
                </div>
            </figure>
        </div>
    </section>

    {{-- Impact strip: the demo's real ledger totals since launch. Totals only, never a name (ADR 0017). --}}
    <section aria-labelledby="demo-totals-heading" class="border-b border-line bg-surface" data-demo-totals>
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 id="demo-totals-heading" class="text-3xl font-semibold text-primary">The demo program so far</h2>
                <p class="rounded-full border-2 border-dashed border-muted px-3 py-1 text-sm font-semibold text-muted">Demo data: all people and transactions are fictional.</p>
            </div>
            @if ($demoTotals['visits'] === 0)
                <p class="mt-4 max-w-2xl text-muted">No cleared redemptions yet. Once employees spend their credits, the totals from the credit ledger appear here.</p>
            @else
                <p class="mt-3 max-w-2xl text-muted">Read live from the credit ledger: cleared redemptions only, since launch{{ $demoRefunded ? ', less completed refunds' : '' }}. Held and denied redemptions don't count.</p>
                <dl class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        [$demoRefunded ? 'Spent at independent merchants, net of refunds' : 'Spent at independent merchants', \App\Support\Money::format($demoTotals['spent_cents'])],
                        ['Merchants reached', number_format($demoTotals['merchants'])],
                        ['Employees participating', number_format($demoTotals['employees'])],
                        ['Employers participating', number_format($demoTotals['employers'])],
                    ] as [$label, $figure])
                        <div class="flex flex-col-reverse">
                            <dt class="mt-1 text-sm font-semibold text-muted">{{ $label }}</dt>
                            <dd class="tabular font-display text-4xl font-semibold text-primary">{{ $figure }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>
    </section>

    {{-- Impact: one worked, illustrative scenario (not real data) --}}
    <section aria-labelledby="impact-heading" class="border-b border-line bg-band">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 id="impact-heading" class="text-3xl font-semibold text-primary">What one district program could add up to</h2>
                <p class="rounded-full border-2 border-dashed border-muted px-3 py-1 text-sm font-semibold text-muted">Illustrative scenario</p>
            </div>
            <p class="mt-3 max-w-2xl text-muted">
                A worked example, not a result: suppose employers in the district enroll 2,000 employees at $50 a month.
            </p>

            <div class="mt-10 flex flex-wrap items-end gap-x-5 gap-y-6 font-display text-primary" role="group"
                 aria-label="2,000 employees times $50 a month times 12 months equals $1.2 million a year">
                @foreach ([['2,000', 'employees'], ['$50', 'per month'], ['12', 'months']] as $i => [$figure, $unit])
                    @if ($i > 0)
                        <span class="pb-7 text-3xl text-muted sm:text-4xl" aria-hidden="true">×</span>
                    @endif
                    <div aria-hidden="true">
                        <p class="tabular text-4xl font-semibold sm:text-5xl">{{ $figure }}</p>
                        <p class="mt-1 font-sans text-sm text-muted">{{ $unit }}</p>
                    </div>
                @endforeach
                <span class="pb-7 text-3xl text-muted sm:text-4xl" aria-hidden="true">=</span>
                <div aria-hidden="true">
                    <p class="tabular rounded-xl bg-accent px-3 text-4xl font-semibold text-on-accent sm:text-5xl">$1.2M</p>
                    <p class="mt-1 font-sans text-sm text-muted">a year</p>
                </div>
            </div>

            <p class="mt-8 max-w-2xl text-lg text-ink">
                That is <strong class="font-semibold">$1.2M a year spent at independent district businesses</strong>,
                because credits can't be spent anywhere else.
            </p>
            <p class="mt-4">
                <a href="{{ route('projection') }}" class="tap-target font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">See the impact projection for one district, DC and nationally</a>
            </p>
        </div>
    </section>

    {{-- Request a demo --}}
    <section id="request-demo" aria-labelledby="request-heading" class="mx-auto max-w-6xl scroll-mt-6 px-4 py-20 sm:px-6">
        <x-card padding="p-8 sm:p-12" class="grid gap-8 lg:grid-cols-[1.4fr_1fr] lg:items-center">
            <div>
                <h2 id="request-heading" class="text-4xl font-semibold text-primary">Request a demo</h2>
                <p class="mt-3 max-w-xl text-lg text-muted">
                    Online demo booking is coming soon. The working product is live: demo logins for an employee
                    and a BID admin are available on request, and contest judges get a personal sign-in link with
                    no password. Already have a login? Log in to redeem credits as an employee or review the AI
                    fraud check from the BID's side.
                </p>
            </div>
            <div class="flex flex-col gap-3">
                <x-button size="lg" :href="route('login')">Log in</x-button>
                <x-button variant="secondary" size="lg" :href="route('eval')">See the AI eval results</x-button>
            </div>
        </x-card>
    </section>
@endsection
