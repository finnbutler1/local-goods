@php use App\Support\Money; use App\Support\SignalPresenter; @endphp

<x-app-shell title="Fraud review" active="bid-admin"
             :context="$bid->name.' BID · '.$bid->city">
<div class="space-y-8">
    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">{{ $bid->name }} BID · {{ $bid->city }}</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Redemption Fraud Detection</h1>
        <p class="mt-3 text-lg text-muted">
            Every new redemption is scored by AI from anonymous signals (no names) before any merchant is paid.
            The AI can be wrong, so a held redemption is never approved or denied automatically: a person at the BID decides.
            If the AI check fails for any reason, the redemption is held, never cleared.
        </p>
        <p class="mt-3 flex flex-wrap items-center gap-2 text-sm text-muted">
            <x-money-mode />
            {{ \App\Support\StripeMode::active()
                ? 'Held redemptions never reach Stripe. Approving one funded by Stripe-paid credits sends its one Stripe test-mode transfer to the merchant; denying sends nothing, ever.'
                : 'Stripe isn\'t connected on this server: approving a redemption records what the merchant is owed, and payouts are simulated.' }}
        </p>
        <p class="mt-3">
            <a href="{{ route('bid-admin.eval') }}" class="font-semibold text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">
                How often does the AI agree with a person? See the eval results <span aria-hidden="true">→</span>
            </a>
        </p>
    </header>

    <div aria-live="polite">
        <x-flash />
        @if ($example = $exampleId ? $redemptions->firstWhere('id', $exampleId) : null)
            <div class="rounded-2xl border-2 {{ $example->isUnderReview() ? 'border-hold bg-hold-bg' : 'border-ok bg-ok-bg' }} p-4" tabindex="-1" data-flash data-collusion-example="{{ $example->id }}">
                <p class="font-semibold">
                    Collusion example: {{ Money::format($example->amount_cents) }} at {{ $example->merchant->name }} by {{ $example->employee->publicLabel() }} (synthetic).
                    The real AI {{ $example->scored_by === 'fallback' ? 'check failed, so it was held automatically' : ($example->isUnderReview() ? 'held it' : 'cleared it') }}.
                </p>
                <p class="mt-1 text-sm">
                    @if ($example->isUnderReview())
                        No money moves until a person approves it below.
                    @else
                        An honest miss: the AI judged this one ordinary, so the gate let it through like any cleared redemption.
                    @endif
                    <a href="#redemption-{{ $example->id }}" class="font-semibold underline underline-offset-4">See it below</a>
                </p>
            </div>
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

        <div class="flex flex-wrap items-start gap-4">
        @can('run-collusion-example')
            <form method="POST" action="{{ route('bid-admin.collusion-example') }}" data-busy="Asking the AI… this can take a few seconds">
                @csrf
                <x-button type="submit" variant="accent" aria-describedby="example-help">Run a collusion example</x-button>
                <p id="example-help" class="mt-1.5 max-w-xs text-sm text-muted">A fresh synthetic redemption at Farragut Grind with a credit-to-cash pattern, checked by the real AI. One AI call.</p>
            </form>
        @endcan
        @can('reset-demo')
            <form method="POST" action="{{ route('bid-admin.reset') }}" data-busy="Resetting the demo…"
                  onsubmit="return confirm('Reset the demo? This removes every redemption made since the last deploy and restores the seeded data.');">
                @csrf
                <x-button type="submit" variant="secondary" aria-describedby="reset-help">Reset demo</x-button>
                <p id="reset-help" class="mt-1.5 text-sm text-muted">Restores the seeded demo data only.</p>
            </form>
        @endcan
        </div>
    </div>

    <section aria-labelledby="recent-heading">
        <h2 id="recent-heading" class="text-2xl font-semibold text-forest">Recent redemptions</h2>
        @if ($redemptions->isEmpty())
            <x-card class="mt-3 border-2 border-dashed border-line ring-0! text-center" padding="px-6 py-10">
                <p class="font-semibold">No redemptions yet.</p>
                <p class="mx-auto mt-1 max-w-md text-muted">When an employee spends credits, the redemption shows here with Redemption Fraud Detection's score, and any it holds wait here for your decision.</p>
            </x-card>
        @else
        <ul class="mt-3 space-y-3">
            @foreach ($redemptions as $redemption)
                <x-card as="li" id="redemption-{{ $redemption->id }}" @class(['scroll-mt-24 border-l-4', 'border-l-hold' => $redemption->isUnderReview(), 'border-l-ok' => $redemption->isApproved(), 'border-l-muted' => $redemption->isRejected()])>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="tabular font-display text-xl font-semibold">{{ Money::format($redemption->amount_cents) }} at {{ $redemption->merchant->name }}</p>
                            <p class="text-sm text-muted">
                                <span title="Employee initials and staff number: names are never shown outside an employee's own wallet">{{ $redemption->employee->publicLabel() }}</span>, {{ $redemption->employee->employer->name }} ·
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

                    <p class="mt-2 text-sm" data-audit-trail>
                        <span class="font-semibold">Path:</span> {{ implode(' → ', $redemption->auditTrail()) }}
                    </p>

                    @if ($redemption->scored_by === 'seed')
                        <p class="mt-3 text-sm text-muted">Historical demo data, recorded before AI scoring was switched on.</p>
                    @else
                        <div class="mt-4 grid gap-4 border-t border-line/70 pt-4 md:grid-cols-2">
                            <div>
                                <h3 class="font-sans text-xs font-semibold uppercase tracking-widest text-muted">AI explanation</h3>
                                <p class="mt-1">{{ $redemption->ai_explanation }}</p>
                                <dl class="mt-2 space-y-0.5 text-sm">
                                    <div><dt class="inline font-semibold">AI decision:</dt> <dd class="inline">{{ $redemption->aiDecisionLabel() }}</dd>@if ($redemption->scored_by !== 'fallback')<span class="text-muted"> · scored by {{ $redemption->scored_by }}</span>@endif</div>
                                    <div><dt class="inline font-semibold">Final decision:</dt> <dd class="inline">{{ $redemption->finalDecisionLabel() }}</dd></div>
                                    @if ($redemption->decision)
                                        <div><dt class="inline font-semibold">Reason:</dt> <dd class="inline">{{ $redemption->decision->reason }}</dd></div>
                                    @endif
                                </dl>
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

                    @if ($redemption->isUnderReview())
                        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-hold-bg p-4">
                            <p class="text-sm text-hold">
                                <span class="font-semibold">@can('review', $redemption) Waiting for your decision. @else Waiting for a BID admin's decision. @endcan</span>
                                The {{ Money::format($redemption->amount_cents) }} is reserved from the employee's balance.
                            </p>
                        </div>
                        @can('review', $redemption)
                            @php $bag = $errors->getBag("review{$redemption->id}"); @endphp
                            <form method="POST" action="{{ route('bid-admin.approve', $redemption) }}" data-busy="Saving your decision…" class="mt-3 space-y-3 rounded-xl border border-hold/30 bg-paper p-4">
                                @csrf
                                <div class="space-y-1.5">
                                    <label for="reason-{{ $redemption->id }}" class="block font-semibold">Reason for your decision <span class="font-normal text-muted">(required, kept with the decision)</span></label>
                                    <textarea id="reason-{{ $redemption->id }}" name="reason" rows="2" required minlength="5" maxlength="500"
                                              aria-describedby="reason-{{ $redemption->id }}-hint{{ $bag->has('reason') ? ' reason-'.$redemption->id.'-error' : '' }}"
                                              @if ($bag->has('reason')) aria-invalid="true" @endif
                                              class="block w-full rounded-xl border-2 {{ $bag->has('reason') ? 'border-hold' : 'border-muted' }} bg-paper px-3 py-2 focus-visible:border-forest">{{ $bag->any() ? old('reason') : '' }}</textarea>
                                    <p id="reason-{{ $redemption->id }}-hint" class="text-sm text-muted">For example: "Regular customer, receipt checked with the merchant." 5 to 500 characters.</p>
                                    @if ($bag->has('reason'))
                                        <p id="reason-{{ $redemption->id }}-error" class="text-sm font-semibold text-hold"><span aria-hidden="true">⚑</span> {{ $bag->first('reason') }}</p>
                                    @endif
                                </div>
                                <fieldset class="space-y-1.5" @if ($bag->has('denial')) role="radiogroup" aria-invalid="true" aria-describedby="denial-{{ $redemption->id }}-error" @endif>
                                    <legend class="font-semibold">If you deny it, where does the credit go?</legend>
                                    @foreach (\App\Enums\DenialReason::cases() as $why)
                                        <label class="flex items-start gap-2 text-sm">
                                            <input type="radio" name="denial" value="{{ $why->value }}" class="mt-0.5" @checked($bag->any() && old('denial') === $why->value)>
                                            <span>{{ $why->label() }}</span>
                                        </label>
                                    @endforeach
                                    @if ($bag->has('denial'))
                                        <p id="denial-{{ $redemption->id }}-error" class="text-sm font-semibold text-hold"><span aria-hidden="true">⚑</span> {{ $bag->first('denial') }}</p>
                                    @endif
                                </fieldset>
                                <div class="flex flex-wrap gap-2">
                                    <x-button type="submit" size="sm" aria-label="Approve {{ Money::format($redemption->amount_cents) }} at {{ $redemption->merchant->name }}">Approve</x-button>
                                    <x-button type="submit" size="sm" variant="danger" class="bg-paper" formaction="{{ route('bid-admin.deny', $redemption) }}" aria-label="Deny {{ Money::format($redemption->amount_cents) }} at {{ $redemption->merchant->name }}">Deny</x-button>
                                </div>
                                <p class="text-sm text-muted">Approve pays the merchant (one transfer, never two). Deny means no transfer, ever.</p>
                            </form>
                        @endcan
                    @elseif ($redemption->reviewed_by)
                        <p class="mt-4 text-sm text-muted">
                            {{ $redemption->isApproved() ? 'The reserved credits were spent.' : 'Denied: the credits went back to the '.($redemption->decision?->credit_returned_to ?? 'employee').'.' }}
                        </p>
                    @endif
                </x-card>
            @endforeach
        </ul>
        @endif
    </section>
</div>
</x-app-shell>
