@extends('layouts.adaptive')

@section('title', 'Coming next · Local Goods')

{{-- Roadmap ideas drawn as concepts. Nothing here is built: every card says so
     at the top and in its accessible name. HTML and CSS only (no images, no
     logos). Mentions of Apple Pay, Google Pay, DoorDash Drive and Uber Direct
     are plain text and name no relationship. Keep dollar figures away from
     words like fee or revenue (PublicClaimsTest). --}}
@php
    $label = 'Concept, not built';
    $phone = 'mx-auto w-full max-w-[16rem] rounded-[2rem] border-[3px] border-frame bg-page p-3';
    $screen = 'rounded-[1.4rem] bg-surface p-4 ring-2 ring-inset ring-line';
    $placeholder = 'h-2.5 rounded-full bg-band';
@endphp

@section('content')
    <div class="wrap">
        <header class="page-head">
            <x-station-sign as="p">Coming next</x-station-sign>
            <h1 class="page-title">Coming next</h1>
            <p class="page-lede">
                Ideas for where Local Goods could go, drawn as sketches so you can picture them.
                None of them is built, none has a date, and each one says what it would take.
            </p>
        </header>

        <div class="stop-line stop-line-dense">
            {{-- 1. A card in a phone wallet --}}
            <section id="wallet-card" aria-label="{{ $label }}: A Local Goods card in a phone wallet" class="stop scroll-mt-24">
                <x-stop-number :n="1" />
                <p><x-badge tone="hold">{{ $label }}</x-badge></p>
                <x-station-sign as="h2" class="mt-3">A Local Goods card in a phone wallet</x-station-sign>
                <div class="mt-6 grid gap-8 md:grid-cols-12 md:items-start">
                    <div class="md:col-span-5">
                        <div role="img" class="{{ $phone }}" aria-label="{{ $label }}: a drawn phone showing a generic Local Goods card with a demo available balance of $42.50.">
                            <div aria-hidden="true" class="{{ $screen }}">
                                <div class="mx-auto mb-4 h-1.5 w-16 rounded-full bg-edge"></div>
                                <div class="rounded-md bg-night p-4 text-on-night">
                                    <div class="flex items-center justify-between gap-2 text-[0.8125rem]">
                                        <span class="font-extrabold">Local Goods</span>
                                        <span class="rounded-[3px] px-1.5 text-xs font-semibold text-night-muted ring-1 ring-night-muted">Demo</span>
                                    </div>
                                    <p class="mt-6 text-[0.8125rem] font-bold uppercase tracking-[0.12em] text-night-muted">Available</p>
                                    <p class="money text-[2rem] leading-tight">$42.50</p>
                                </div>
                                <div class="mt-4 space-y-2">
                                    <div class="{{ $placeholder }} w-3/4"></div>
                                    <div class="{{ $placeholder }} w-1/2"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="md:col-span-7">
                        <h3 class="text-lg font-bold">What it would take</h3>
                        <ul class="mt-2 max-w-[42rem]">
                            <li class="border-b border-line py-3">A card program through Stripe Issuing, which needs approval and a verified business account (<a href="https://github.com/finnbutler1/local-goods/blob/main/docs/adr/0036-no-card-preview-yet.md" class="font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover" rel="noopener">why we haven't built it</a>).</li>
                            <li class="border-b border-line py-3">Approval to add the cards to Apple Pay and Google Pay. Employers can already pay for credits with Apple Pay today; adding the employee's card to Apple Pay is the next step.</li>
                            <li class="border-b border-line py-3">A card that works only at approved local shops, so staff keep paying locally.</li>
                        </ul>
                    </div>
                </div>
            </section>

            {{-- 2. Delivery for Order to go --}}
            <section id="delivery" aria-label="{{ $label }}: Delivery for Order to go" class="stop scroll-mt-24">
                <x-stop-number :n="2" />
                <p><x-badge tone="hold">{{ $label }}</x-badge></p>
                <x-station-sign as="h2" class="mt-3">Delivery for Order to go</x-station-sign>
                <div class="mt-6 grid gap-8 md:grid-cols-12 md:items-start">
                    <div class="md:col-span-5">
                        <div role="img" class="{{ $phone }}" aria-label="{{ $label }}: a drawn order screen with Pickup chosen and a Delivery choice greyed out and marked Concept.">
                            <div aria-hidden="true" class="{{ $screen }}">
                                <p class="font-extrabold">Order to go</p>
                                <div class="mt-3 grid grid-cols-2 gap-2 text-sm font-bold">
                                    <span class="flex min-h-11 items-center justify-center rounded-[4px] bg-primary px-2 text-on-primary">Pickup</span>
                                    <span class="flex min-h-11 flex-col items-center justify-center rounded-[4px] bg-disabled-bg px-2 text-disabled-ink ring-2 ring-inset ring-edge">
                                        Delivery
                                        <span class="text-xs font-semibold">Concept</span>
                                    </span>
                                </div>
                                <div class="mt-4 space-y-2">
                                    <div class="{{ $placeholder }} w-full"></div>
                                    <div class="{{ $placeholder }} w-2/3"></div>
                                    <div class="{{ $placeholder }} w-1/2"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="md:col-span-7">
                        <h3 class="text-lg font-bold">What it would take</h3>
                        <ul class="mt-2 max-w-[42rem]">
                            <li class="border-b border-line py-3">A delivery partner, such as DoorDash Drive or Uber Direct.</li>
                            <li class="border-b border-line py-3">An answer to who pays the delivery fees.</li>
                            <li class="border-b border-line py-3">A way to keep credits spendable only at local merchants.</li>
                        </ul>
                        <p class="mt-4 max-w-[42rem] font-semibold">We have not partnered with any delivery service.</p>
                    </div>
                </div>
            </section>

            {{-- 3. Credits for residents --}}
            <section id="residents" aria-label="{{ $label }}: Credits for residents" class="stop scroll-mt-24">
                <x-stop-number :n="3" />
                <p><x-badge tone="hold">{{ $label }}</x-badge></p>
                <x-station-sign as="h2" class="mt-3">Credits for residents</x-station-sign>
                <div class="mt-6 grid gap-8 md:grid-cols-12 md:items-start">
                    <div class="md:col-span-5">
                        <div role="img" class="mx-auto w-full max-w-[20rem]" aria-label="{{ $label }}: a drawn building amenity card reading Funded by your building, with a demo balance of $60.00.">
                            <div aria-hidden="true" class="rounded-md border-2 border-frame bg-primary-tint p-4 text-ink">
                                <div class="flex items-center justify-between gap-2 text-[0.8125rem]">
                                    <span class="font-extrabold">Funded by your building</span>
                                    <span class="rounded-[3px] bg-surface px-1.5 text-xs font-semibold text-ink ring-1 ring-edge">Demo</span>
                                </div>
                                <p class="mt-6 text-[0.8125rem] font-bold uppercase tracking-[0.12em]">Balance</p>
                                <p class="money text-[2rem] leading-tight">$60.00</p>
                                <p class="mt-2 text-sm">Spend at local shops, evenings and weekends</p>
                            </div>
                        </div>
                    </div>
                    <div class="md:col-span-7">
                        <h3 class="text-lg font-bold">What it would take</h3>
                        <ul class="mt-2 max-w-[42rem]">
                            <li class="border-b border-line py-3">Property owners willing to fund credits for residents who live downtown.</li>
                            <li class="border-b border-line py-3">Credits spent locally on evenings and weekends, at the same independent merchants.</li>
                            <li class="border-b border-line py-3">A second kind of funder next to employers.</li>
                        </ul>
                    </div>
                </div>
            </section>

            {{-- 4. AI suggests merchants for delivery --}}
            <section id="ai-delivery-merchants" aria-label="{{ $label }}: AI suggests merchants for delivery" class="stop scroll-mt-24">
                <x-stop-number :n="4" />
                <p><x-badge tone="hold">{{ $label }}</x-badge></p>
                <x-station-sign as="h2" class="mt-3">AI suggests merchants for delivery</x-station-sign>
                <div class="mt-6 grid gap-8 md:grid-cols-12 md:items-start">
                    <div class="md:col-span-5">
                        <div role="img" class="mx-auto w-full max-w-[20rem]" aria-label="{{ $label }}: a drawn example list of three kinds of shop an AI might suggest for delivery, to be reviewed by a person.">
                            <div aria-hidden="true" class="rounded-md border-2 border-frame bg-surface p-4">
                                <p class="font-extrabold">Suggested for delivery</p>
                                <p class="text-sm text-muted">Example only, for a person to review</p>
                                <ol class="mt-4 space-y-3 text-sm font-semibold">
                                    @foreach (['Coffee and breakfast', 'Lunch counters', 'Snacks and groceries'] as $kind)
                                        <li class="flex items-center gap-3">
                                            <span class="grid size-7 place-items-center rounded-full font-mono text-xs ring-2 ring-inset ring-ink">{{ $loop->iteration }}</span>
                                            {{ $kind }}
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        </div>
                    </div>
                    <div class="md:col-span-7">
                        <h3 class="text-lg font-bold">What it would take</h3>
                        <ul class="mt-2 max-w-[42rem]">
                            <li class="border-b border-line py-3">AI that suggests which merchants to bring on for delivery, based on what employees order most.</li>
                            <li class="border-b border-line py-3">Its own locked prompt and test set, like the two AI functions we have now.</li>
                            <li class="border-b border-line py-3">A person making every decision. The AI would only recommend.</li>
                        </ul>
                    </div>
                </div>
            </section>
        </div>

        <p class="mt-10 max-w-[42rem] pb-12 text-sm text-muted">
            These ideas came from feedback from a business improvement district director.
            Naming a company on this page is not a relationship with it.
        </p>
    </div>
@endsection
