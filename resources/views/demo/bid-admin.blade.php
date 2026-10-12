@php use App\Support\Money; use App\Support\SignalPresenter; @endphp

<x-app-shell title="BID admin" active="bid-admin"
             user-name="Sam Taylor (demo)" role="BID admin"
             :context="$bid->name.' BID · '.$bid->city">
<div class="space-y-8">
    <x-demo-nav current="bid-admin" />

    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">{{ $bid->name }} BID · {{ $bid->city }}</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Redemption Fraud Detection</h1>
        <p class="mt-3 text-lg text-muted">
            Every new redemption is scored by AI from anonymous signals (no names) before any merchant is paid.
            The AI can be wrong, so a held redemption is never rejected automatically: a person reviews it.
            If the AI check fails for any reason, the redemption is held, never cleared.
        </p>
        <p class="mt-3">
            <a href="{{ route('demo.bid-admin.eval') }}" class="font-semibold text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">
                How often does the AI agree with a person? See the eval results <span aria-hidden="true">→</span>
            </a>
        </p>
    </header>

    <div aria-live="polite">
        @if (session('status'))
            <p class="rounded-2xl border-2 border-ok bg-ok-bg p-4 font-semibold text-ok">{{ session('status') }}</p>
        @endif
    </div>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <dl class="grid w-full grid-cols-2 gap-3 sm:w-md">
            <x-card padding="p-4 sm:p-5">
                <dt class="text-sm font-semibold text-muted">AI-scored redemptions</dt>
                <dd class="tabular mt-1 font-display text-4xl font-semibold text-forest">{{ $scoredCount }}</dd>
            </x-card>
            <div class="rounded-2xl bg-hold-bg p-4 sm:p-5">
                <dt class="text-sm font-semibold text-hold">Under review</dt>
                <dd class="tabular mt-1 font-display text-4xl font-semibold text-hold">{{ $heldCount }}</dd>
            </div>
        </dl>

        <form method="POST" action="{{ route('demo.reset') }}"
              onsubmit="return confirm('Reset the demo? This removes every redemption made since the last deploy and restores the seeded data.');">
            @csrf
            <x-button type="submit" variant="secondary" aria-describedby="reset-help">Reset demo</x-button>
            <p id="reset-help" class="mt-1.5 text-sm text-muted">Restores the seeded demo data only.</p>
        </form>
    </div>

    <section aria-labelledby="recent-heading">
        <h2 id="recent-heading" class="text-2xl font-semibold text-forest">Recent redemptions</h2>
        <ul class="mt-3 space-y-3">
            @foreach ($redemptions as $redemption)
                <x-card as="li" @class(['border-l-4', 'border-l-hold' => ! $redemption->isApproved(), 'border-l-ok' => $redemption->isApproved()])>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="tabular font-display text-xl font-semibold">{{ Money::format($redemption->amount_cents) }} at {{ $redemption->merchant->name }}</p>
                            <p class="text-sm text-muted">
                                {{ $redemption->employee->display_name }}, {{ $redemption->employee->employer->name }} ·
                                {{ $redemption->created_at->setTimezone('America/New_York')->format('M j, g:i A') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($redemption->risk_score !== null)
                                <span class="tabular text-sm font-semibold">Risk {{ $redemption->risk_score }}/100</span>
                            @endif
                            <x-status-badge :redemption="$redemption" />
                        </div>
                    </div>

                    @if ($redemption->scored_by === 'seed')
                        <p class="mt-3 text-sm text-muted">Historical demo data, recorded before AI scoring was switched on.</p>
                    @else
                        <div class="mt-4 grid gap-4 border-t border-line/70 pt-4 md:grid-cols-2">
                            <div>
                                <h3 class="font-sans text-xs font-semibold uppercase tracking-widest text-muted">AI explanation</h3>
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
                                <h3 class="font-sans text-xs font-semibold uppercase tracking-widest text-muted">Signals sent to the AI</h3>
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
                </x-card>
            @endforeach
        </ul>
    </section>
</div>
</x-app-shell>
