@extends('layouts.app')

@section('title', 'Who does what · Local Goods')

@php
    use App\Http\Controllers\Districts\WhoDoesWhatController as Steps;

    $link = 'font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary';
    $tone = [Steps::BID => 'accent', Steps::LOCAL_GOODS => 'primary', Steps::STRIPE => 'neutral'];
    $keys = array_keys($steps);
@endphp

@section('content')
    <div class="mx-auto max-w-6xl space-y-10 px-4 py-12 sm:px-6">
        <header class="max-w-3xl">
            <x-eyebrow class="text-accent-ink">For Business Improvement Districts</x-eyebrow>
            <h1 class="mt-1 text-title font-semibold text-primary">Who does what</h1>
            <p class="mt-3 text-lg text-muted">
                Why a BID would use Local Goods rather than build this itself: each step from a shop applying to the BID's report,
                who handles it and what the software does. The BID keeps the decisions that need local judgment; the rest is software
                and Stripe.
            </p>
        </header>

        <section aria-labelledby="flow-heading" id="flow" class="scroll-mt-24">
            <h2 id="flow-heading" class="text-2xl font-semibold text-primary">The eight steps</h2>
            <p class="mt-1 text-sm text-muted">Choose a step. Arrow keys move between steps.</p>
            <div class="mt-4" data-tabs>
                <div role="tablist" aria-label="Steps" class="grid grid-cols-2 gap-1 rounded-2xl border-2 border-line bg-surface p-1 sm:grid-cols-4 lg:grid-cols-8">
                    @foreach ($steps as $key => $s)
                        <a role="tab" id="step-tab-{{ $key }}" href="{{ route('who-does-what', ['step' => $key]) }}#flow"
                           aria-controls="step-panel-{{ $key }}" aria-selected="{{ $step === $key ? 'true' : 'false' }}" tabindex="{{ $step === $key ? '0' : '-1' }}"
                           class="flex min-h-11 items-center justify-center gap-1.5 rounded-xl px-2 py-2 text-center font-semibold text-primary hover:bg-primary-tint aria-selected:bg-primary aria-selected:text-on-primary">
                            <span class="tabular" aria-hidden="true">{{ $loop->iteration }}</span> {{ $s['title'] }}
                        </a>
                    @endforeach
                </div>

                @foreach ($steps as $key => $s)
                    <div role="tabpanel" id="step-panel-{{ $key }}" aria-labelledby="step-tab-{{ $key }}" tabindex="0" class="mt-4" @if ($step !== $key) hidden @endif>
                        <x-card>
                            <h3 class="text-xl font-semibold text-primary">Step {{ $loop->iteration }} of {{ count($steps) }}: {{ $s['title'] }}</h3>
                            <p class="mt-2 flex flex-wrap items-center gap-2"><span class="text-sm text-muted">Handled by</span>
                                @foreach ($s['who'] as $who) <x-badge :tone="$tone[$who]">{{ $who }}</x-badge> @endforeach
                            </p>
                            <dl class="mt-4 grid gap-4 md:grid-cols-2">
                                <div class="rounded-2xl bg-primary-tint p-4">
                                    <dt class="font-semibold">What Local Goods' software does</dt>
                                    <dd class="mt-1">{{ $s['software'] }}</dd>
                                </div>
                                <div class="rounded-2xl bg-band p-4">
                                    <dt class="font-semibold">What the BID does</dt>
                                    <dd class="mt-1">{{ $s['bid'] }}</dd>
                                </div>
                            </dl>
                            <p class="mt-4">
                                <a href="{{ route($s['route']) }}" class="tap-target {{ $link }}">Open {{ $s['screen'] }} <span aria-hidden="true">→</span></a>
                                <span class="block text-sm text-muted">Who can open it: {{ $s['access'] }}.</span>
                            </p>
                        </x-card>
                    </div>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="all-heading" class="space-y-3">
            <h2 id="all-heading" class="text-2xl font-semibold text-primary">All steps at a glance</h2>
            <x-rtable label="Who does what table">
                <table data-rtable="stack" class="w-full text-left">
                    <caption class="sr-only">Each step, who handles it and what the BID does</caption>
                    <thead class="border-b border-line/70 text-sm text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold sm:px-6">Step</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Handled by</th>
                            <th scope="col" class="px-5 py-3 font-semibold">What the BID does</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/70">
                        @foreach ($steps as $s)
                            <tr>
                                <td data-primary class="px-5 py-3 font-semibold sm:px-6">{{ $loop->iteration }}. {{ $s['title'] }}</td>
                                <td data-label="Handled by" class="px-5 py-3">{{ implode(', ', $s['who']) }}</td>
                                <td data-label="What the BID does" class="px-5 py-3">{{ $s['bid'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
        </section>

        <section aria-labelledby="honest-heading" class="grid gap-4 md:grid-cols-2">
            <h2 id="honest-heading" class="sr-only">What each side keeps</h2>
            <x-card>
                <h3 class="text-xl font-semibold text-primary">The BID keeps</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>The relationship with employers and shops in its district</li>
                    <li>Every decision about which shops join</li>
                    <li>The review of payments the fraud check holds</li>
                    <li>Its district's aggregate data, to export at any time</li>
                </ul>
            </x-card>
            <x-card>
                <h3 class="text-xl font-semibold text-primary">What Local Goods doesn't do</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Hold the money: Stripe does, in test mode in this prototype</li>
                    <li>Let the AI approve a shop or a held payment</li>
                    <li>Show the BID or anyone else employee names</li>
                    <li>Issue cards: the employee's phone is the card</li>
                </ul>
            </x-card>
        </section>

        <p class="text-sm text-muted">
            This is a student prototype. The Golden Triangle partnership in the demo is not an actual agreement.
            <a href="{{ route('district') }}" class="{{ $link }}">See the district map</a>.
        </p>
    </div>
@endsection
