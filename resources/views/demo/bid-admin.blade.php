@php use App\Support\Money; use App\Support\SignalPresenter; @endphp
@extends('layouts.app')

@section('title', 'BID admin · Local Goods')

@section('content')
<div class="mx-auto max-w-5xl px-4 py-8 space-y-6">
    <x-demo-nav current="bid-admin" />

    <header>
        <p class="text-sm font-semibold uppercase tracking-widest text-muted">{{ $bid->name }} BID · {{ $bid->city }}</p>
        <h1 class="mt-1 text-3xl font-semibold tracking-tight">Redemption Fraud Detection</h1>
        <p class="mt-2 max-w-2xl text-muted">
            Every new redemption is scored by AI from anonymous signals (no names) before any merchant is paid.
            The AI can be wrong, so a held redemption is never rejected automatically: a person reviews it.
            If the AI check fails for any reason, the redemption is held, never cleared.
        </p>
    </header>

    <div aria-live="polite">
        @if (session('status'))
            <p class="rounded-xl border-2 border-ok bg-ok-bg p-4 font-semibold text-ok">{{ session('status') }}</p>
        @endif
    </div>

    <div class="flex flex-wrap items-end justify-between gap-4">
    <dl class="grid grid-cols-2 gap-3 sm:w-md">
        <div class="rounded-xl bg-white p-4 ring-1 ring-cream-deep">
            <dt class="text-sm text-muted">AI-scored redemptions</dt>
            <dd class="text-2xl font-semibold">{{ $scoredCount }}</dd>
        </div>
        <div class="rounded-xl bg-hold-bg p-4">
            <dt class="text-sm text-hold">Under review</dt>
            <dd class="text-2xl font-semibold text-hold">{{ $heldCount }}</dd>
        </div>
    </dl>

    <form method="POST" action="{{ route('demo.reset') }}"
          onsubmit="return confirm('Reset the demo? This removes every redemption made since the last deploy and restores the seeded data.');">
        @csrf
        <button type="submit" aria-describedby="reset-help"
                class="rounded-lg border-2 border-forest px-4 py-2.5 font-semibold text-forest hover:bg-forest hover:text-cream">
            Reset demo
        </button>
        <p id="reset-help" class="mt-1 text-sm text-muted">Restores the seeded demo data only.</p>
    </form>
    </div>

    <section aria-labelledby="recent-heading">
        <h2 id="recent-heading" class="text-xl font-semibold">Recent redemptions</h2>
        <ul class="mt-3 space-y-3">
            @foreach ($redemptions as $redemption)
                <li class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-cream-deep">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-lg font-semibold">{{ Money::format($redemption->amount_cents) }} at {{ $redemption->merchant->name }}</p>
                            <p class="text-sm text-muted">
                                {{ $redemption->employee->display_name }}, {{ $redemption->employee->employer->name }} ·
                                {{ $redemption->created_at->setTimezone('America/New_York')->format('M j, g:i A') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($redemption->risk_score !== null)
                                <span class="text-sm font-semibold">Risk {{ $redemption->risk_score }}/100</span>
                            @endif
                            <x-status-badge :redemption="$redemption" />
                        </div>
                    </div>

                    @if ($redemption->scored_by === 'seed')
                        <p class="mt-3 text-sm text-muted">Historical demo data, recorded before AI scoring was switched on.</p>
                    @else
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <div>
                                <h3 class="text-sm font-semibold uppercase tracking-wider text-muted">AI explanation</h3>
                                <p class="mt-1">{{ $redemption->ai_explanation }}</p>
                                <p class="mt-2 text-sm text-muted">
                                    @if ($redemption->scored_by === 'fallback')
                                        Automatic hold: the AI check did not complete.
                                    @else
                                        Decision: {{ $redemption->ai_decision }} · scored by {{ $redemption->scored_by }}
                                    @endif
                                </p>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold uppercase tracking-wider text-muted">Signals sent to the AI</h3>
                                <dl class="mt-1 space-y-1 text-sm">
                                    @foreach (SignalPresenter::rows($redemption->signals ?? []) as $row)
                                        <div class="flex gap-2">
                                            <dt class="w-44 shrink-0 text-muted">{{ $row['label'] }}</dt>
                                            <dd @class(['font-semibold text-hold' => $row['flagged']])>
                                                @if ($row['flagged'])<span aria-hidden="true">⚑ </span><span class="sr-only">Flagged: </span>@endif{{ $row['value'] }}
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
</div>
@endsection
