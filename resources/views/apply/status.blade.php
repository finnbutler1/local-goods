@extends('layouts.app')

@section('title', 'Application status · Local Goods')

@php
    use App\Models\MerchantApplication;

    $link = 'font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary';
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-8 px-4 py-12 sm:px-6">
        @include('apply.partials.prototype-notice')

        @if (! $application)
            <x-card as="section" aria-labelledby="open-heading">
                <h1 id="open-heading" class="text-title font-semibold text-primary">Your application</h1>
                <p class="mt-2 text-muted" id="status-link-intro">
                    Open the private link you saved after applying. If it doesn't open by itself, paste the whole link here.
                </p>
                @include('apply.partials.status-link-form')
            </x-card>
        @else
            <header>
                <x-eyebrow class="text-accent-ink">Your application</x-eyebrow>
                <h1 class="mt-1 text-title font-semibold text-primary">{{ $application->business_name }}</h1>
                <p class="mt-1 text-muted">{{ $application->address }} · {{ $application->categoryLabel() }}</p>
                @if ($application->is_sample)
                    <p class="mt-2"><x-badge tone="neutral">{{ MerchantApplication::SAMPLE_LABEL }}</x-badge></p>
                @endif
                <p class="mt-4 text-xl font-semibold" data-step-sentence>{{ $application->stepSentence() }}</p>
            </header>

            <x-card as="section" aria-labelledby="steps-heading">
                <h2 id="steps-heading" class="text-xl font-semibold text-primary">Steps</h2>
                <ol class="mt-4 space-y-3" data-step-tracker>
                    @foreach (array_values(MerchantApplication::STEPS) as $i => $label)
                        @php
                            $number = $i + 1;
                            $current = $application->stepNumber();
                            $declinedHere = $application->status === MerchantApplication::DECLINED && $number === 3;
                            $done = $application->status === MerchantApplication::LIVE ? true : $number < $current;
                            $state = $declinedHere ? 'Declined' : ($done ? 'Done' : ($number === $current ? 'Now' : ($application->status === MerchantApplication::DECLINED ? 'Not reached' : 'Next')));
                        @endphp
                        <li class="flex items-center gap-3" @if ($number === $current && ! $done) aria-current="step" @endif>
                            <span @class(['grid size-9 shrink-0 place-items-center rounded-full font-semibold',
                                'bg-ok-bg text-ok' => $done, 'bg-hold-bg text-hold' => $declinedHere,
                                'bg-primary text-on-primary' => ! $done && ! $declinedHere && $number === $current,
                                'bg-band text-muted' => ! $done && ! $declinedHere && $number !== $current]) aria-hidden="true">{{ $done ? '✓' : ($declinedHere ? '✕' : $number) }}</span>
                            <span><span class="font-semibold">Step {{ $number }}: {{ $label }}</span> <span class="text-muted">· {{ $state }}</span></span>
                        </li>
                    @endforeach
                </ol>
            </x-card>

            @if ($application->status === MerchantApplication::DECLINED)
                <x-card as="section" aria-labelledby="declined-heading">
                    <h2 id="declined-heading" class="text-xl font-semibold text-primary">Why it was declined</h2>
                    <p class="mt-2" data-decline-reason>{{ $application->decline_reason }}</p>
                    <p class="mt-2 text-sm text-muted">If something here is wrong, you can apply again with the details corrected.</p>
                </x-card>
            @elseif (in_array($application->status, [MerchantApplication::BID_APPROVED, MerchantApplication::STRIPE_ONBOARDING], true))
                <x-card as="section" aria-labelledby="payouts-heading">
                    <h2 id="payouts-heading" class="text-xl font-semibold text-primary">Set up payouts</h2>
                    <p class="mt-2">
                        Sign in as {{ $application->business_name }} and connect a Stripe account in test mode, so Local Goods can pay you
                        for credits spent at your shop. In this prototype no real money moves.
                    </p>
                    <form method="POST" action="{{ route('apply.status.sign-in') }}" data-busy="Signing you in…" class="mt-4">
                        @csrf
                        <x-button type="submit">{{ $application->status === MerchantApplication::BID_APPROVED ? 'Sign in and set up payouts' : 'Sign in to carry on' }}</x-button>
                    </form>
                </x-card>
            @elseif ($application->status === MerchantApplication::LIVE)
                <x-card as="section" aria-labelledby="live-heading">
                    <h2 id="live-heading" class="text-xl font-semibold text-primary">You're live</h2>
                    <p class="mt-2">Your shop is on the <a href="{{ route('district') }}" class="{{ $link }}">district map</a>, and employees can spend credits there.</p>
                </x-card>
            @endif

            <section aria-labelledby="history-heading" class="space-y-3">
                <h2 id="history-heading" class="text-2xl font-semibold text-primary">History</h2>
                <ol class="space-y-3">
                    @foreach ($application->events as $event)
                        <li class="rounded-2xl bg-surface p-4 ring-1 ring-line" data-application-event>
                            <p class="font-semibold">{{ MerchantApplication::STEPS[$event->to_status] ?? 'Declined' }}</p>
                            <p class="text-sm text-muted">{{ $event->created_at->setTimezone(config('fraud.timezone'))->format('M j, Y g:i A') }} (DC time) · {{ $event->actorLabel() }}</p>
                            <p class="mt-1">{{ $event->reason }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>

            {{-- Opening another application's link here switches to it (the form also picks up a link's # part). --}}
            <details class="rounded-2xl bg-band p-4" @if ($errors->has('link')) open @endif>
                <summary class="cursor-pointer font-semibold text-primary">Follow a different application</summary>
                @include('apply.partials.status-link-form')
            </details>
        @endif
    </div>
@endsection
