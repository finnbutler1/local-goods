@extends('layouts.app')

@section('title', 'Local Goods: You work here. Spend here.')

@php
    // The fictional demo merchants as stops on the hero map (ADR 0038), placed
    // where DatabaseSeeder puts them; side says where each label sits so none
    // collide. Farragut Grind, the demo merchant login's shop, is the local stop.
    $labelSides = ['kitchen' => 'above', 'noodle' => 'left', 'salon' => 'below-right', 'cafe' => 'below'];
    $mapStops = collect(\Database\Seeders\DatabaseSeeder::DEMO_MERCHANTS)->map(fn ($merchant, $key) => [
        'name' => $merchant[0],
        'lat' => \Database\Seeders\DatabaseSeeder::DEMO_COORDINATES[$key][0],
        'lng' => \Database\Seeders\DatabaseSeeder::DEMO_COORDINATES[$key][1],
        'side' => $labelSides[$key] ?? 'right',
        'lines' => $key === 'kitchen' ? ['Seventeenth Street', 'Kitchen'] : [$merchant[0]],
    ])->values()->all();
    $link = 'tap-target font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover';
@endphp

@section('content')
    {{-- Hero: the headline and the workday on the left, the district as a transit diagram on the right --}}
    <section aria-labelledby="hero-heading" class="overflow-x-clip pt-12 lg:pt-16">
        <div class="wrap grid-12 items-start">
            <div class="col-span-12 lg:col-span-6 lg:pt-3">
                <h1 id="hero-heading" class="hero-title">
                    <span>You work here.</span>
                    <span>Spend here.</span>
                </h1>
                <p class="sign-line mt-6">For employers in the Golden Triangle · Washington, DC</p>
                <p class="mt-6 max-w-[34rem] text-[1.1875rem] leading-relaxed">
                    At 6 p.m., downtown's workers head home, and their spending goes with them. Local Goods gives employees
                    credits that only work at independent local businesses, so the district your company calls home gets livelier.
                </p>
                {{-- The commute-desk-commute loop, rerouted through one local stop. --}}
                <ol class="route-line mt-9" aria-label="A workday, rerouted">
                    <li>Home</li>
                    <li>Desk</li>
                    <li class="route-line-local"><strong>Farragut Grind</strong> <span class="local-tag">local stop</span></li>
                    <li>Home</li>
                </ol>
                <div class="mt-8 flex flex-wrap gap-3">
                    <x-button variant="accent" size="lg" :href="\App\Support\DemoRequestLink::url()" aria-label="Request a demo (opens your email)">Request a demo</x-button>
                    <x-button variant="secondary" size="lg" :href="route('login')">Log in</x-button>
                    @if ($publicJudgeUrl)
                        <x-button variant="secondary" size="lg" rel="nofollow" :href="$publicJudgeUrl">Look around (read-only)</x-button>
                    @elseif ($continueLooking)
                        <x-button variant="secondary" size="lg" :href="route('judge.dashboard')">Continue looking around</x-button>
                    @endif
                </div>
                <p class="mt-3.5 max-w-[34rem] text-sm text-muted">A working prototype: payments run on Stripe in test mode, and every person and business in the demo is fictional.</p>
            </div>

            <div class="col-span-12 mt-10 lg:col-span-6 lg:col-start-7 lg:mt-0">
                <x-district-diagram id="hero-map" class="lg:-mt-6" :stops="$mapStops" :local="config('demo.accounts.merchant.business')"
                    :title="'Map of the Golden Triangle district: '.count($mapStops).' demo merchants as stops on one line, with Connecticut Ave, K St, 17th St and Pennsylvania Ave.'" />

                {{-- Illustration of the wallet, built in HTML so it stays sharp and needs no image file. --}}
                <div class="fare-card" role="img"
                     aria-label="Example employee wallet: $120.00 available, with a $12.90 lunch at Press Room Deli approved after an AI fraud check.">
                    <div aria-hidden="true" class="contents">
                        <div class="fare-card-head">
                            <div class="flex flex-wrap items-center justify-between gap-x-2.5 gap-y-1 text-[0.8125rem]">
                                <span class="font-bold uppercase tracking-[0.12em] text-night-muted">Available</span>
                                <span class="flex items-center gap-1.5">
                                    <span class="font-extrabold">Local Goods</span>
                                    <span class="rounded-[3px] px-1.5 text-xs font-semibold text-night-muted ring-1 ring-night-muted">Demo</span>
                                </span>
                            </div>
                            <p class="money mt-1.5 text-[2.75rem] leading-tight lg:text-[clamp(2rem,3.2vw,2.75rem)]">$120.00</p>
                            <p class="text-[0.8125rem] text-night-muted">$150.00 added monthly by your employer</p>
                        </div>
                        <ul class="px-5 text-sm lg:pt-1.5">
                            @foreach ([['Press Room Deli', 'Lunch · 12:15 PM', '$12.90'], ['Farragut Grind', 'Coffee · 8:55 AM', '$7.25'], ['Page & Quill Books', 'Books · 1:05 PM', '$13.00']] as [$name, $when, $amount])
                                <li class="flex justify-between gap-3 border-b border-line py-2.5 lg:last:border-b-0">
                                    <div>
                                        <p class="font-bold">{{ $name }}</p>
                                        <p class="text-muted">{{ $when }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="ledger-num font-semibold">{{ $amount }}</p>
                                        <x-badge tone="ok"><span class="size-1.5 rounded-full bg-ok"></span>Approved</x-badge>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        <p class="fare-card-check flex items-center gap-2 px-5 py-3 text-sm font-bold text-primary lg:border-t lg:border-line">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="size-4.5 shrink-0"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                            AI fraud check passed
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Every later section is a numbered stop on one line down the page (ADR 0038). --}}
    <div class="wrap">
        <div class="stop-line">
            {{-- The problem: the commute-desk-commute loop, named without blaming anyone --}}
            <section id="the-problem" aria-labelledby="problem-heading" class="stop">
                <x-stop-number n="1" />
                <x-station-sign as="p">The problem</x-station-sign>
                <h2 id="problem-heading" class="stop-title">The commute-desk-commute loop</h2>
                <p class="stop-body">
                    Thousands of people work in the Golden Triangle every weekday. Most follow the same loop: commute in, sit at a desk,
                    commute out. That loop comes from how office work is set up, and the people in it aren't to blame. But it means their spending,
                    and the life they could bring to the streets around them, goes home with them.
                </p>
            </section>

            {{-- How it works --}}
            <section id="how-it-works" aria-labelledby="how-heading" class="stop">
                <x-stop-number n="2" />
                <x-station-sign as="p">How it works</x-station-sign>
                <h2 id="how-heading" class="stop-title">Three steps from payroll perk to a busier block.</h2>
                <ol class="steps">
                    @foreach ([
                        ['Your company buys credits', 'Fund a monthly allowance for each employee on one invoice. Staff need nothing beyond the app.'],
                        ['Employees spend them nearby', 'Credits work only at independent restaurants, shops, salons and gyms inside the district. Staff spend them from the app on their phone.'],
                        ['The money stays in the neighborhood', 'Every redemption is checked for abuse before a merchant is paid. Payments and merchant payouts run on Stripe in test mode: real Stripe transactions, no real money.'],
                    ] as $i => [$title, $body])
                        <li>
                            <span class="step-number" aria-hidden="true">{{ $i + 1 }}</span>
                            <h3 class="pt-2 text-[1.375rem] leading-tight font-extrabold"><span class="sr-only">Step {{ $i + 1 }}: </span>{{ $title }}</h3>
                            <p class="mt-2 max-w-md text-muted">{{ $body }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>

            {{-- Why it matters: the district first, then everyone else who gains (ADR 0024) --}}
            <section id="why-it-matters" aria-labelledby="why-heading" class="stop">
                <x-stop-number n="3" />
                <x-station-sign as="p">Why it matters</x-station-sign>
                <h2 id="why-heading" class="stop-title">Everyone gains. The district gains most.</h2>

                <article aria-labelledby="gain-district" class="rule-heavy mt-10 lg:grid lg:grid-cols-11 lg:gap-x-6">
                    <p class="rule-tag lg:col-span-11 lg:justify-self-start">Most of all</p>
                    <div class="mt-3 lg:col-span-5">
                        <h3 id="gain-district" class="text-3xl font-extrabold text-primary">The district</h3>
                    </div>
                    <p class="mt-3 max-w-[40rem] text-lg lg:col-span-6 lg:mt-0">
                        Credits can only be spent at the independent shops, restaurants and services that give the Golden Triangle its
                        character, so they bring foot traffic and spending there. A livelier district is good for everyone who works, lives or does business in it.
                    </p>
                </article>

                <div class="mt-12 grid gap-x-6 gap-y-10 lg:grid-cols-11">
                    <article aria-labelledby="gain-employers" class="rule-top lg:col-span-5 lg:row-span-3">
                        <p class="rule-tag">For HR teams</p>
                        <h3 id="gain-employers" class="mt-3 text-2xl font-extrabold">Employers</h3>
                        <p class="mt-2 text-lg">A benefit your staff will use, because it shows up at lunch near the office.</p>
                        <ul class="mt-5 border-t border-line">
                            @foreach ([
                                'A practical way to improve the streets around your own office.',
                                'A livelier neighborhood can make an office easier to recruit to, easier to stay at and more worth coming in to; a pilot would measure how much.',
                                'Participation and spending reports you can bring to leadership.',
                                'One dashboard to fund the credits and track how they\'re spent.',
                            ] as $point)
                                <li class="flex gap-3 border-b border-line py-3">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                                    <span>{{ $point }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <div class="mt-6">
                            <x-button :href="\App\Support\DemoRequestLink::url()" aria-label="Request a demo for your team (opens your email)">Request a demo for your team</x-button>
                        </div>
                    </article>

                    @foreach ([
                        ['gain-merchants', 'For independents', 'Merchants', 'The district\'s large weekday workforce gets a reason to visit you and to come back. No new hardware: customers show the app at the counter. Payments and merchant payouts run on Stripe in test mode: real Stripe transactions, no real money.', [['apply', 'Apply to join']]],
                        ['gain-employees', 'For staff', 'Employees', 'Credits from your employer cover lunch, coffee and errands near the office. They also give you a reason to explore the neighborhood where you spend most of your week.', []],
                        ['gain-bid', 'For the Golden Triangle', 'The BID', 'It gives the BID a way to support the district\'s independent businesses and measure the result. Spending and participation are counted from the ledger; employees are never named.', [['who-does-what', 'Who does what'], ['district', 'The district map']]],
                    ] as [$id, $eyebrow, $title, $body, $links])
                        <article aria-labelledby="{{ $id }}" class="rule-top lg:col-span-6">
                            <p class="rule-tag">{{ $eyebrow }}</p>
                            <h3 id="{{ $id }}" class="mt-3 text-2xl font-extrabold">{{ $title }}</h3>
                            <p class="mt-2 max-w-[40rem] text-muted">{{ $body }}</p>
                            @if ($links !== [])
                                <p class="mt-3 flex flex-wrap gap-x-5 gap-y-1">
                                    @foreach ($links as [$route, $text])
                                        <a href="{{ route($route) }}" class="{{ $link }}">{{ $text }} <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="inline size-4 align-[-0.125em]" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
                                    @endforeach
                                </p>
                            @endif
                        </article>
                    @endforeach
                </div>

                {{-- Supporting evidence, below the mission, never the hero. Always with its label and source. --}}
                <section aria-label="Outside estimate of local recirculation" class="rule-heavy mt-12 bg-band px-6 pb-6 sm:px-8 sm:pb-8">
                    @include('partials.recirculation-evidence')
                </section>
            </section>

            {{-- Trust and AI --}}
            <section id="trust" aria-labelledby="trust-heading" class="stop">
                <x-stop-number n="4" />
                <x-station-sign as="p">Trust and AI</x-station-sign>
                <h2 id="trust-heading" class="stop-title max-w-[22ch] lg:col-span-11">Every redemption is checked before anyone is paid.</h2>
                <div class="mt-10 grid gap-x-6 gap-y-12 lg:grid-cols-11">
                    <div class="lg:col-span-6">
                        <ul class="border-t-[3px] border-ink">
                            @foreach ([
                                ['Redemption Fraud Detection', 'scores every redemption for abuse, such as turning credits into cash, and explains its reasoning in plain language.'],
                                ['It fails closed.', 'If the AI check errors or gives an unusable answer, the redemption is held. It is never waved through.'],
                                ['The AI flags; people decide anything it holds.', 'Redemption Fraud Detection and Merchant Eligibility Matching flag and explain. A held redemption is never approved automatically; it waits for review by the BID, and a person approves every new merchant.'],
                                ['No names go to the AI.', 'It sees only anonymous signals, like amount, time of day and visit history.'],
                                ['Shops see initials only.', 'A shop only needs to know the payment is real.'],
                                ['Held payments use neutral words.', 'A held payment at a counter says it is held, with no fraud words, so nobody is accused.'],
                                ['Honesty labels stay on phones.', 'Demo data, test mode and "not affiliated" notices are never hidden on a small screen.'],
                            ] as [$lead, $rest])
                                <li class="border-b border-line py-3.5">
                                    <p class="text-muted"><strong class="font-bold text-ink">{{ $lead }}</strong> {{ $rest }}</p>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-5">
                            <a href="{{ route('who-does-what') }}#not-built" class="{{ $link }}">What we chose not to build, and why <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="inline size-4 align-[-0.125em]" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
                        </p>
                    </div>

                    <figure class="rule-heavy self-start lg:col-span-5">
                        <p class="rule-tag">How we measure the AI</p>
                        @if ($evalHeadline)
                            <blockquote class="mt-4">
                                <p class="font-display text-3xl leading-tight font-extrabold tracking-[-0.03em] sm:text-4xl">{{ $evalHeadline['text'] }}</p>
                            </blockquote>
                            <figcaption class="mt-5 space-y-2 text-muted">
                                <p>Compared with a person's labels on hand-labeled test redemptions the AI had never seen.</p>
                                <p class="text-sm">{{ $evalHeadline['sample'] }}</p>
                            </figcaption>
                        @else
                            <p class="mt-4 font-display text-3xl font-extrabold tracking-[-0.03em]">Eval results not yet published.</p>
                            <figcaption class="mt-5 text-muted">We publish how often the AI agrees with a person once the eval has been run.</figcaption>
                        @endif
                        <div class="mt-8">
                            <x-button :href="route('eval')">See the full eval results</x-button>
                        </div>
                    </figure>
                </div>
            </section>

            {{-- Impact strip: the demo's real ledger totals since launch. Totals only, never a name (ADR 0017). --}}
            <section aria-labelledby="demo-totals-heading" class="stop" data-demo-totals>
                <x-stop-number n="5" />
                <x-station-sign heading-id="demo-totals-heading">The demo program so far</x-station-sign>
                <p class="mt-3 text-sm font-bold">Demo data: all people and transactions are fictional.</p>
                @if ($demoTotals['visits'] === 0)
                    <p class="mt-4 max-w-2xl text-muted">No cleared redemptions yet. Once employees spend their credits, the totals from the credit ledger appear here.</p>
                @else
                    <p class="mt-2 max-w-2xl text-muted">Read live from the credit ledger: cleared redemptions only, since launch{{ $demoRefunded ? ', less completed refunds' : '' }}. Held and denied redemptions don't count.</p>
                    <dl class="mt-8 grid gap-x-6 gap-y-8 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            [$demoRefunded ? 'Spent at independent merchants, net of refunds' : 'Spent at independent merchants', \App\Support\Money::format($demoTotals['spent_cents'])],
                            ['Merchants reached', number_format($demoTotals['merchants'])],
                            ['Employees participating', number_format($demoTotals['employees'])],
                            ['Employers participating', number_format($demoTotals['employers'])],
                        ] as [$label, $figure])
                            <div class="rule-top flex flex-col-reverse justify-end">
                                <dt class="mt-1 text-sm font-semibold text-muted">{{ $label }}</dt>
                                <dd class="money text-4xl sm:text-5xl">{{ $figure }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </section>

            {{-- Impact: one worked, illustrative scenario (not real data) --}}
            <section aria-labelledby="impact-heading" class="stop">
                <x-stop-number n="6" />
                <x-station-sign heading-id="impact-heading">What one district program could add up to</x-station-sign>
                <p class="mt-3 text-sm font-bold">Illustrative scenario</p>
                <p class="mt-2 max-w-2xl text-muted">
                    A worked example, not a result: suppose employers in the district enroll 2,000 employees at $50 a month.
                </p>

                <div class="rule-heavy mt-8 flex flex-wrap items-end gap-x-5 gap-y-6" role="group"
                     aria-label="2,000 employees times $50 a month times 12 months equals $1.2 million a year">
                    @foreach ([['2,000', 'employees'], ['$50', 'per month'], ['12', 'months']] as $i => [$figure, $unit])
                        @if ($i > 0)
                            <span class="pb-7 text-3xl font-bold text-muted sm:text-4xl" aria-hidden="true">×</span>
                        @endif
                        <div aria-hidden="true">
                            <p class="money text-4xl sm:text-6xl">{{ $figure }}</p>
                            <p class="mt-1 text-sm text-muted">{{ $unit }}</p>
                        </div>
                    @endforeach
                    <span class="pb-7 text-3xl font-bold text-muted sm:text-4xl" aria-hidden="true">=</span>
                    <div aria-hidden="true">
                        <p class="money text-4xl text-primary sm:text-6xl">$1.2M</p>
                        <p class="mt-1 text-sm text-muted">a year</p>
                    </div>
                </div>

                <p class="mt-8 max-w-2xl text-lg">
                    That is <strong class="font-bold">$1.2M a year spent at independent district businesses</strong>,
                    because credits can't be spent anywhere else.
                </p>
                <p class="mt-3">
                    <a href="{{ route('projection') }}" class="{{ $link }}">See the impact projection for one district, DC and nationally</a>
                </p>
            </section>

            {{-- Request a demo: the last stop on the line --}}
            <section id="request-demo" aria-labelledby="request-heading" class="stop">
                <x-stop-number n="7" />
                <x-station-sign heading-id="request-heading">Request a demo</x-station-sign>
                <div class="mt-8 grid gap-8 lg:grid-cols-11 lg:gap-x-6">
                    <p class="max-w-xl text-lg lg:col-span-6">
                        Online demo booking is coming soon. The working product is live: demo logins for an employee
                        and a BID admin are available on request, and contest judges get a personal sign-in link with
                        no password. Already have a login? Log in to redeem credits as an employee or review the AI
                        fraud check from the BID's side.
                    </p>
                    <div class="flex flex-col gap-3 sm:flex-row lg:col-span-5 lg:flex-col lg:items-start">
                        <div>
                            <x-button size="lg" :href="\App\Support\DemoRequestLink::url()" aria-label="Request a demo (opens your email)">Request a demo</x-button>
                            <p class="mt-2 text-sm text-muted">Or email <span class="wrap-anywhere-safe font-semibold text-ink">{{ \App\Support\DemoRequestLink::address() }}</span></p>
                        </div>
                        <x-button size="lg" :href="route('login')">Log in</x-button>
                        <x-button variant="secondary" size="lg" :href="route('eval')">See the AI eval results</x-button>
                    </div>
                </div>
            </section>
        </div>
    </div>
@endsection
