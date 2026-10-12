@extends('layouts.app')

@section('title', 'Local Goods: Spend local. Prove it.')

@section('content')
    {{-- Hero --}}
    <section aria-labelledby="hero-heading" class="mx-auto grid overflow-x-clip max-w-6xl items-center gap-12 px-4 pt-10 pb-20 sm:px-6 lg:grid-cols-[1.15fr_1fr] lg:pt-16 lg:pb-28">
        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">An employee perk for the Golden Triangle · Washington, DC</p>
            <h1 id="hero-heading" class="mt-4 text-6xl leading-[0.95] font-semibold text-forest sm:text-7xl lg:text-8xl">
                Spend local.<br>
                <span class="relative inline-block">
                    Prove it.
                    <span class="absolute inset-x-0 -bottom-1 h-3 rounded-full bg-amber/70 sm:h-4" aria-hidden="true"></span>
                </span>
            </h1>
            <p class="mt-8 max-w-xl text-lg leading-relaxed text-ink sm:text-xl">
                Give your team a monthly perk they can only spend at the independent cafés, shops and studios around
                your office, so people get out of the building and into the neighborhood.
            </p>
            <div class="mt-10 flex flex-col gap-3 sm:flex-row">
                <x-button variant="accent" size="lg" href="#request-demo">Request a demo</x-button>
                <x-button variant="secondary" size="lg" :href="route('login')">Log in</x-button>
            </div>
            <p class="mt-4 text-sm text-muted">For HR and people teams at employers in the Golden Triangle BID.</p>
        </div>

        {{-- Illustration of the wallet, built in HTML so it stays sharp and needs no image file. --}}
        <div class="relative mx-auto w-full max-w-sm" role="img"
             aria-label="Example employee wallet: $120.00 available, with a $12.90 lunch at Press Room Deli approved after an AI fraud check.">
            <div class="absolute -inset-4 -z-10 rotate-3 rounded-[2.5rem] bg-cream-deep" aria-hidden="true"></div>
            <div aria-hidden="true" class="overflow-hidden rounded-[2rem] bg-paper shadow-[0_30px_60px_-30px_rgb(31_61_43/0.55)] ring-1 ring-line">
                <div class="relative bg-forest px-6 pt-7 pb-8 text-cream">
                    <div class="awning absolute inset-x-0 top-0 h-1.5 opacity-60"></div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-semibold uppercase tracking-widest text-sage">Available</span>
                        <span class="font-display font-semibold">Local Goods</span>
                    </div>
                    <p class="tabular mt-3 font-display text-6xl font-semibold text-amber">$120.00</p>
                    <p class="mt-2 text-sm text-sage">$150.00 added monthly by your employer</p>
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
            <div aria-hidden="true" class="absolute -bottom-5 -left-1 flex items-center gap-2 rounded-full bg-forest py-2 pr-4 pl-2 text-sm font-semibold text-cream shadow-lg sm:-left-10">
                <span class="grid size-7 place-items-center rounded-full bg-amber text-ink">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="size-4"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                </span>
                AI fraud check passed
            </div>
            <p aria-hidden="true" class="absolute -top-3 right-4 rounded-full bg-amber px-3 py-1 text-xs font-semibold text-ink">Demo</p>
        </div>
    </section>

    {{-- How it works --}}
    <section id="how-it-works" aria-labelledby="how-heading" class="scroll-mt-6 border-y border-line bg-paper">
        <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
            <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">How it works</p>
            <h2 id="how-heading" class="mt-2 max-w-2xl text-4xl font-semibold text-forest sm:text-5xl">Three steps from payroll perk to a busier block.</h2>
            <div class="relative mt-14">
            {{-- Runs from the centre of circle 1 to the centre of circle 3 (circles are 3.5rem, column gap 2rem),
                 so the circles cover both ends exactly. Keep in sync with size-14 and md:gap-8 below. --}}
            <div class="ledger-rule absolute top-[1.625rem] left-7 hidden h-1 w-[calc((100%_-_4rem)*2/3_+_4rem)] md:block" aria-hidden="true"></div>
            <ol class="relative grid gap-12 md:grid-cols-3 md:gap-8">
                @foreach ([
                    ['Your company buys credits', 'Fund a monthly allowance for each employee. One invoice, no cards to issue, nothing for staff to sign up for beyond an app.'],
                    ['Employees spend them nearby', 'Credits work only at independent restaurants, shops, salons and gyms inside the district. The phone is the card.'],
                    ['The money stays in the neighborhood', 'Merchants are paid out directly, and every redemption is checked for abuse before a cent moves.'],
                ] as $i => [$title, $body])
                    <li class="relative">
                        <span class="relative grid size-14 place-items-center rounded-full bg-forest font-display text-2xl font-semibold text-amber" aria-hidden="true">{{ $i + 1 }}</span>
                        <h3 class="mt-5 text-2xl font-semibold text-forest"><span class="sr-only">Step {{ $i + 1 }}: </span>{{ $title }}</h3>
                        <p class="mt-2 text-muted">{{ $body }}</p>
                    </li>
                @endforeach
            </ol>
            </div>
        </div>
    </section>

    {{-- Audiences: employers first and strongest --}}
    <section id="who-its-for" aria-labelledby="who-heading" class="mx-auto max-w-6xl scroll-mt-6 px-4 py-20 sm:px-6">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Who it's for</p>
        <h2 id="who-heading" class="mt-2 max-w-2xl text-4xl font-semibold text-forest sm:text-5xl">Good for the office. Good for the block.</h2>

        <div class="mt-12 grid gap-4 lg:grid-cols-4">
            <article aria-labelledby="aud-employers" class="relative flex flex-col overflow-hidden rounded-3xl bg-forest p-8 text-cream lg:col-span-2 lg:row-span-2 lg:p-10">
                <div class="awning absolute inset-x-0 top-0 h-2 opacity-60" aria-hidden="true"></div>
                <x-badge tone="amber" class="self-start">For HR teams</x-badge>
                <h3 id="aud-employers" class="mt-5 text-4xl font-semibold">Employers</h3>
                <p class="mt-3 text-lg text-sage">A perk people actually use, and a reason to leave the desk at lunch.</p>
                <ul class="mt-8 space-y-4">
                    @foreach ([
                        'A visible monthly benefit that shows up in people\'s day, not in a portal they forget.',
                        'Gets teams out of the office and into the neighborhood together.',
                        'Participation and spend reports you can bring to leadership.',
                        'No cards to issue: the employee\'s phone is the card.',
                    ] as $point)
                        <li class="flex gap-3">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 size-5 shrink-0 text-amber" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-auto pt-10">
                    <x-button variant="accent" href="#request-demo">Request a demo for your team</x-button>
                </div>
            </article>

            <x-card as="article" aria-labelledby="aud-bid" class="lg:col-span-2" padding="p-8">
                <p class="text-sm font-semibold uppercase tracking-widest text-muted">For the district</p>
                <h3 id="aud-bid" class="mt-2 text-3xl font-semibold text-forest">The BID</h3>
                <p class="mt-3 text-muted">More district spending you can measure. Every credit is spent inside the Golden Triangle, at businesses that keep the streets lively, and every redemption is on the record.</p>
            </x-card>

            <x-card as="article" aria-labelledby="aud-merchants" class="lg:col-span-2" padding="p-8">
                <p class="text-sm font-semibold uppercase tracking-widest text-muted">For independents</p>
                <h3 id="aud-merchants" class="mt-2 text-3xl font-semibold text-forest">Merchants</h3>
                <p class="mt-3 text-muted">New customers from the office towers next door, with no setup cost and no new hardware. Customers show the app at the counter and you're paid out directly.</p>
            </x-card>
        </div>
    </section>

    {{-- Trust and AI --}}
    <section id="trust" aria-labelledby="trust-heading" class="scroll-mt-6 bg-forest text-cream">
        <div class="mx-auto grid max-w-6xl gap-12 px-4 py-20 sm:px-6 lg:grid-cols-2 lg:gap-16">
            <div>
                <p class="text-sm font-semibold uppercase tracking-widest text-amber">Trust and AI</p>
                <h2 id="trust-heading" class="mt-2 text-4xl font-semibold sm:text-5xl">Every redemption is checked before anyone is paid.</h2>
                <ul class="mt-8 space-y-5">
                    @foreach ([
                        ['Redemption Fraud Detection', 'scores every redemption for abuse, such as turning credits into cash, and explains its reasoning in plain language.'],
                        ['It fails closed.', 'If the AI check errors or gives an unusable answer, the redemption is held. It is never waved through.'],
                        ['A person makes the final call.', 'A held redemption is never approved automatically; it waits for review by the BID.'],
                        ['No names go to the AI.', 'It sees only anonymous signals, like amount, time of day and visit history.'],
                    ] as [$lead, $rest])
                        <li class="flex gap-3">
                            <span class="mt-2 size-2 shrink-0 rounded-full bg-amber" aria-hidden="true"></span>
                            <p class="text-sage"><strong class="font-semibold text-cream">{{ $lead }}</strong> {{ $rest }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>

            <figure class="self-center rounded-3xl bg-cream p-8 text-ink sm:p-10">
                <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Measured, not promised</p>
                @if ($evalHeadline)
                    <blockquote class="mt-4">
                        <p class="font-display text-3xl leading-tight font-semibold text-forest sm:text-4xl">{{ $evalHeadline['text'] }}</p>
                    </blockquote>
                    <figcaption class="mt-5 space-y-2 text-muted">
                        <p>Compared with a person's labels on hand-labeled test redemptions the AI had never seen.</p>
                        <p class="text-sm">{{ $evalHeadline['sample'] }}</p>
                    </figcaption>
                @else
                    <p class="mt-4 font-display text-3xl font-semibold text-forest">Eval results not yet published.</p>
                    <figcaption class="mt-5 text-muted">We publish how often the AI agrees with a person once the eval has been run.</figcaption>
                @endif
                <div class="mt-8">
                    <x-button :href="route('eval')">See the full eval results</x-button>
                </div>
            </figure>
        </div>
    </section>

    {{-- Impact: one worked, illustrative scenario (not real data) --}}
    <section aria-labelledby="impact-heading" class="border-b border-line bg-cream-deep">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 id="impact-heading" class="text-3xl font-semibold text-forest">What one district program could add up to</h2>
                <p class="rounded-full border-2 border-dashed border-muted px-3 py-1 text-sm font-semibold text-muted">Illustrative scenario</p>
            </div>
            <p class="mt-3 max-w-2xl text-muted">
                A worked example, not a result: suppose employers in the district enroll 2,000 employees at $50 a month.
            </p>

            <div class="mt-10 flex flex-wrap items-end gap-x-5 gap-y-6 font-display text-forest" role="group"
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
                    <p class="tabular rounded-xl bg-amber px-3 text-4xl font-semibold text-ink sm:text-5xl">$1.2M</p>
                    <p class="mt-1 font-sans text-sm text-muted">a year</p>
                </div>
            </div>

            <p class="mt-8 max-w-2xl text-lg text-ink">
                That is <strong class="font-semibold">$1.2M a year spent at independent district businesses</strong>,
                because credits can't be spent anywhere else.
            </p>
        </div>
    </section>

    {{-- Request a demo --}}
    <section id="request-demo" aria-labelledby="request-heading" class="mx-auto max-w-6xl scroll-mt-6 px-4 py-20 sm:px-6">
        <x-card padding="p-8 sm:p-12" class="grid gap-8 lg:grid-cols-[1.4fr_1fr] lg:items-center">
            <div>
                <h2 id="request-heading" class="text-4xl font-semibold text-forest">Request a demo</h2>
                <p class="mt-3 max-w-xl text-lg text-muted">
                    Online demo booking is coming soon. The working product is live: demo logins for an employee,
                    a BID admin and contest judges are available on request. Already have one? Log in to redeem
                    credits as an employee or review the AI fraud check from the BID's side.
                </p>
            </div>
            <div class="flex flex-col gap-3">
                <x-button size="lg" :href="route('login')">Log in</x-button>
                <x-button variant="secondary" size="lg" :href="route('eval')">See the AI eval results</x-button>
            </div>
        </x-card>
    </section>
@endsection
