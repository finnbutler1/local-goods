@extends('layouts.adaptive')

@section('title', 'Who does what · Local Goods')

@php
    use App\Http\Controllers\Districts\WhoDoesWhatController as Steps;

    $link = 'font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover';
    $tone = [Steps::BID => 'accent', Steps::LOCAL_GOODS => 'primary', Steps::STRIPE => 'neutral'];
    $keys = array_keys($steps);
@endphp

@section('content')
    <div class="wrap">
        <header class="page-head">
            <x-station-sign as="p">For Business Improvement Districts</x-station-sign>
            <h1 class="page-title">Who does what</h1>
            <p class="page-lede">
                This page explains why a BID would use Local Goods rather than build this itself. It walks through each step from a
                shop applying to the BID's report, who handles it and what the software does. The BID keeps the decisions that need local judgment; the rest is software
                and Stripe.
            </p>
        </header>

        <div class="stop-line stop-line-dense">
        <section aria-labelledby="flow-heading" id="flow" class="stop scroll-mt-24">
            <x-stop-number n="1" />
            <x-station-sign heading-id="flow-heading" :dots="count($steps)">The eight steps</x-station-sign>
            <p class="mt-4 text-sm text-muted">Choose a step. Arrow keys move between steps.</p>
            <div class="mt-6" data-tabs>
                <div role="tablist" aria-label="Steps" class="step-line">
                    @foreach ($steps as $key => $s)
                        <a role="tab" id="step-tab-{{ $key }}" href="{{ route('who-does-what', ['step' => $key]) }}#flow"
                           aria-controls="step-panel-{{ $key }}" aria-selected="{{ $step === $key ? 'true' : 'false' }}" tabindex="{{ $step === $key ? '0' : '-1' }}">
                            <span class="step-line-stop tabular" aria-hidden="true">{{ $loop->iteration }}</span> <span class="step-line-name">{{ $s['title'] }}</span>
                        </a>
                    @endforeach
                </div>

                @foreach ($steps as $key => $s)
                    <div role="tabpanel" id="step-panel-{{ $key }}" aria-labelledby="step-tab-{{ $key }}" tabindex="0" class="rule-heavy mt-8" @if ($step !== $key) hidden @endif>
                        <h3 class="stop-subtitle">Step {{ $loop->iteration }} of {{ count($steps) }}: {{ $s['title'] }}</h3>
                        <p class="mt-3 flex flex-wrap items-center gap-2"><span class="text-sm text-muted">Handled by</span>
                            @foreach ($s['who'] as $who) <x-badge :tone="$tone[$who]">{{ $who }}</x-badge> @endforeach
                        </p>
                        <dl class="mt-6 grid gap-x-6 gap-y-6 md:grid-cols-2">
                            <div class="rule-top">
                                <dt class="font-bold">What Local Goods' software does</dt>
                                <dd class="mt-1.5">{{ $s['software'] }}</dd>
                            </div>
                            <div class="rule-top">
                                <dt class="font-bold">What the BID does</dt>
                                <dd class="mt-1.5">{{ $s['bid'] }}</dd>
                            </div>
                        </dl>
                        <p class="mt-6">
                            <a href="{{ route($s['route']) }}" class="tap-target {{ $link }}">Open {{ $s['screen'] }} <span aria-hidden="true">→</span></a>
                            <span class="block text-sm text-muted">Who can open it: {{ $s['access'] }}.</span>
                        </p>
                    </div>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="all-heading" class="stop">
            <x-stop-number n="2" />
            <x-station-sign heading-id="all-heading">All steps at a glance</x-station-sign>
            <x-rtable class="mt-6" label="Who does what table">
                <table data-rtable="stack" class="w-full text-left">
                    <caption class="sr-only">Each step, who handles it and what the BID does</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="py-3 pr-4">Step</th>
                            <th scope="col" class="py-3 pr-4">Handled by</th>
                            <th scope="col" class="py-3">What the BID does</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($steps as $s)
                            <tr>
                                <td data-primary class="py-3 pr-4 font-bold">{{ $loop->iteration }}. {{ $s['title'] }}</td>
                                <td data-label="Handled by" class="py-3 pr-4">{{ implode(', ', $s['who']) }}</td>
                                <td data-label="What the BID does" class="py-3">{{ $s['bid'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
        </section>

        <section aria-labelledby="honest-heading" class="stop">
            <x-stop-number n="3" />
            <h2 id="honest-heading" class="sr-only">What each side keeps</h2>
            <div class="grid gap-x-6 gap-y-10 md:grid-cols-2">
                @foreach ([
                    ['The BID keeps', ['The relationship with employers and shops in its district', 'Every decision about which shops join', 'The review of payments the fraud check holds', 'Its district\'s aggregate data, to export at any time']],
                    ['What Local Goods doesn\'t do', ['Hold the money: Stripe does, in test mode in this prototype', 'Let the AI approve a shop or a held payment', 'Show the BID or anyone else employee names', 'Issue cards: the employee\'s phone is the card']],
                ] as [$title, $items])
                    <div>
                        <x-station-sign as="h3">{{ $title }}</x-station-sign>
                        <ul class="mt-4">
                            @foreach ($items as $item)
                                <li class="border-b border-line py-3">{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="not-built-heading" id="not-built" class="stop scroll-mt-24">
            <x-stop-number n="4" />
            <x-station-sign heading-id="not-built-heading">What we chose not to build, and why</x-station-sign>
            <p class="mt-4 max-w-[42rem] text-muted">Saying no was part of the design. Each decision below is already in our decision records, with the reason.</p>
            @php
                $notBuilt = [
                    ['The merchant fee ships switched off. Nothing is charged, and a test stops any seed from turning it on.', "We haven't talked to merchants about pricing yet, so it stays off until customer discovery tells us what is fair."],
                    ['A payout that was reversed in the Stripe dashboard is not re-sent by us. It is refused, and a person sorts it out in Stripe.', 'A second automatic money path on top of a reversal Stripe itself made is easy to get wrong, and we won\'t add one before judging.'],
                    ['Unspent credits are never counted as revenue.', "They are the employer's money, and a test checks that revenue doesn't move with the share spent."],
                    ['No issued card, so no interchange.', 'The MVP has no card program, and we could not verify the interchange rate, so the model keeps it off.'],
                    ['No admin "Cancel" button on a held in-person payment.', 'The shop is the one who knows the customer paid another way, so only the shop can cancel. An admin\'s Deny already returns the credits.'],
                    ['No card preview: a Stripe Issuing test card next to pay codes.', "Issuing switches on instantly in test mode, but funding cards needs a business account behind an identity check, which we chose not to complete with invented details. We won't show a fake card instead."],
                    ["Merchants can't sell gift cards, gift certificates or other cash-like items for credits.", 'Buying one with credits would turn credits into cash that could be spent anywhere, which breaks the rule that credits are only spent at local independent shops. The planned employer-funded prepaid card would enforce the same principle from the other side, by refusing everywhere except approved shops.'],
                    ['No email sending in the prototype.', "There is no mail service, so we don't promise a reset link that would never arrive. An applicant's private status link is shown once instead."],
                    ['The Merchant Eligibility Matching AI has no web search.', 'Adding it means a new prompt version and a fresh unseen test set. Until then it says "undetermined" and a person decides.'],
                    ["We don't reset the live demo for convenience.", 'Reset demo would erase real examples judges can see on the live site, such as the in-person payment, taxed orders and a completed correction. It is for local use only.'],
                    ['No BID or employer initiation fee in the product.', 'It is already a lever in the projection model, and a new money path adds risk before judging.'],
                    ['No business-model figures on public pages, and the local-spending estimate only with its outside-study label and source.', 'The figures are a projection, not results, and the estimate comes from an outside study that Local Goods has not measured.'],
                    ['A fee charged during a three-minute check that the fee setting worked is kept as earned income, not given back.', 'On Oct 28, from 12:08 to 12:11 (fee audit log), the merchant fee was switched on live to check that it worked. One sale was charged a few cents. We considered building a way to give it back. We decided that a new money path for so small an amount, this close to the freeze, was the wrong trade. Instead we recorded exactly what happened and treated it as earned. Otherwise Local Goods charges nothing.'],
                    ['Customer money is never invested or lent, and new purchases never pay old promises.', "Unspent credits are the employer's money. A guard on every money operation refuses anything that would leave less money than has been promised. A check over the whole ledger history reports any moment when that happened."],
                ];
            @endphp
            <x-rtable class="mt-6" label="What we chose not to build">
                <table data-rtable="stack" class="w-full text-left">
                    <caption class="sr-only">Each decision and the reason for it</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="w-5/12 py-3 pr-6">Decision</th>
                            <th scope="col" class="py-3">Reason</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($notBuilt as [$decision, $reason])
                            <tr class="align-top">
                                <td data-primary class="py-3.5 pr-6 font-bold">{{ $decision }}</td>
                                <td data-label="Reason" class="py-3.5">{{ $reason }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
        </section>
        </div>

        <p class="max-w-[42rem] pb-12 text-sm text-muted">
            This is a student prototype. The Golden Triangle partnership in the demo is not an actual agreement.
            <a href="{{ route('district') }}" class="{{ $link }}">See the district map</a>.
        </p>
    </div>
@endsection
