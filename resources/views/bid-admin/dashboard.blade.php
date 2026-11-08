@php use App\Support\Money; use App\Support\SignalPresenter; @endphp

<x-app-shell title="Fraud Review" active="fraud-review"
             :context="$bid->name.' BID · '.$bid->city">
<div class="admin-desk space-y-12">
    <header>
        <x-station-sign as="p">{{ $bid->name }} BID · {{ $bid->city }}</x-station-sign>
        <h1 class="page-title">Fraud Review</h1>
        <p class="mt-3 text-2xl font-bold tracking-tight text-ink">Redemption Fraud Detection</p>
        <p class="page-lede">
            Every new redemption is scored by AI from anonymous signals (no names) before any merchant is paid.
            The AI can be wrong, so a held redemption is never approved or denied automatically: a person at the BID decides.
            If the AI check fails for any reason, the redemption is held, never cleared.
        </p>
        <p class="mt-4 flex max-w-[52rem] flex-wrap items-center gap-2 text-sm text-muted">
            <x-money-mode />
            {{ \App\Support\StripeMode::active()
                ? 'Held redemptions never reach Stripe. Approving one funded by Stripe-paid credits sends its one Stripe test-mode transfer to the merchant; denying sends nothing, ever.'
                : 'Stripe isn\'t connected on this server: approving a redemption records what the merchant is owed, and payouts are simulated.' }}
        </p>
    </header>

    @include('partials.waiting-for-a-person')

    <div aria-live="polite">
        @if ($example = $exampleId ? $redemptions->firstWhere('id', $exampleId) : null)
            <div class="border-t-[3px] {{ $example->isUnderReview() ? 'border-hold bg-hold-bg text-hold' : 'border-ok bg-ok-bg text-ok' }} px-4 py-4 sm:px-5" tabindex="-1" data-flash data-collusion-example="{{ $example->id }}">
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

    <div class="grid-12 gap-y-8">
        <dl class="col-span-12 grid grid-cols-2 gap-x-6 lg:col-span-5">
            <div class="rule-top">
                <dt class="figure-label">AI-scored redemptions</dt>
                <dd class="money mt-2 text-5xl">{{ $scoredCount }}</dd>
            </div>
            <div class="rule-top border-hold">
                <dt class="figure-label text-hold">Under review</dt>
                <dd class="money mt-2 text-5xl text-hold">{{ $heldCount }}</dd>
            </div>
        </dl>

        <div class="col-span-12 flex flex-wrap items-start gap-x-8 gap-y-6 lg:col-span-7 lg:justify-end lg:pt-4">
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
        <h2 id="recent-heading" class="stop-subtitle text-2xl">Recent redemptions</h2>
        @if ($redemptions->isEmpty())
            <div class="ledger-note mt-4 border-dashed px-6 py-10">
                <p class="font-semibold">No redemptions yet.</p>
                <p class="mt-1 max-w-md text-muted">When an employee spends credits, the redemption shows here with Redemption Fraud Detection's score, and any it holds wait here for your decision.</p>
            </div>
        @else
        <ul class="entry-list mt-4">
            @foreach ($redemptions as $redemption)
                <li id="redemption-{{ $redemption->id }}" @class(['scroll-mt-24', 'border-b-[3px]! border-b-ink! pb-8!' => $redemption->isUnderReview(), 'py-4!' => $redemption->scored_by === 'seed'])>
                    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2">
                        <div class="min-w-0">
                            <p @class(['money', 'text-2xl' => $redemption->isUnderReview(), 'text-xl' => ! $redemption->isUnderReview()])>{{ Money::format($redemption->amount_cents) }} at {{ $redemption->merchant->name }}</p>
                            @if ($for = $redemption->itemsSummary())
                                <p class="break-words" data-items>{{ $for }} <span class="text-sm text-muted">(items are display only: not sent to Redemption Fraud Detection)</span></p>
                            @endif
                            <p class="text-sm text-muted">
                                <span title="Employee initials and staff number: names are never shown outside an employee's own wallet">{{ $redemption->employee->publicLabel() }}</span>, {{ $redemption->employee->employer->name }} ·
                                {{ $redemption->created_at->setTimezone('America/New_York')->format('M j, g:i A') }}
                            </p>
                            <p class="text-sm text-muted" data-channel>Channel: {{ $redemption->channelLabel() }} <span>(not sent to Redemption Fraud Detection)</span></p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($redemption->risk_score !== null)
                                <span class="money text-sm">Risk {{ $redemption->risk_score }}/100</span>
                            @endif
                            @if ($redemption->isGeneratedDemoActivity()) <x-demo-activity-badge /> @endif
                            <x-status-badge :redemption="$redemption" />
                        </div>
                    </div>

                    <p class="mt-2 text-sm" data-audit-trail>
                        <span class="font-semibold">Path:</span> {{ implode(' → ', $redemption->auditTrail()) }}
                    </p>

                    @if ($redemption->scored_by === 'seed')
                        <p class="mt-1 text-sm text-muted">Historical demo data, recorded before AI scoring was switched on.</p>
                    @else
                        <div class="mt-5 grid gap-x-8 gap-y-6 md:grid-cols-12">
                            <div class="md:col-span-7">
                                <h3 class="entry-label">AI explanation</h3>
                                <p class="mt-1.5 max-w-[60ch] text-lg leading-relaxed">{{ $redemption->ai_explanation }}</p>
                                <dl class="mt-3 space-y-0.5 text-sm">
                                    <div><dt class="inline font-semibold">AI decision:</dt> <dd class="inline">{{ $redemption->aiDecisionLabel() }}</dd>@if ($redemption->scored_by !== 'fallback')<span class="text-muted"> · scored by {{ $redemption->scored_by }}</span>@endif</div>
                                    <div><dt class="inline font-semibold">Final decision:</dt> <dd class="inline">{{ $redemption->finalDecisionLabel() }}</dd></div>
                                    @if ($redemption->decision)
                                        <div><dt class="inline font-semibold">Reason:</dt> <dd class="inline">{{ $redemption->decision->reason }}</dd></div>
                                    @endif
                                </dl>
                            </div>
                            <div class="md:col-span-5">
                                <h3 class="entry-label">Signals sent to the AI</h3>
                                <dl class="mt-1.5 border-t border-ink text-sm">
                                    @foreach (SignalPresenter::rows($redemption->signals ?? []) as $row)
                                        <div class="flex gap-3 border-b border-line py-1.5">
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
                        <div class="decision-panel mt-6">
                        <div class="decision-panel-head">
                            <p class="text-sm">
                                <span class="font-bold">@can('review', $redemption) Waiting for your decision. @else Waiting for a BID admin's decision. @endcan</span>
                                The {{ Money::format($redemption->amount_cents) }} is reserved from the employee's balance.
                            </p>
                        </div>
                        @can('review', $redemption)
                            @php $bag = $errors->getBag("review{$redemption->id}"); @endphp
                            <form method="POST" action="{{ route('bid-admin.approve', $redemption) }}" data-busy="Saving your decision…" class="space-y-4 p-4 sm:p-5">
                                @csrf
                                <div class="space-y-1.5">
                                    <label for="reason-{{ $redemption->id }}" class="block font-semibold">Reason for your decision <span class="font-normal text-muted">(required, kept with the decision)</span></label>
                                    <textarea id="reason-{{ $redemption->id }}" name="reason" rows="2" required minlength="5" maxlength="500"
                                              aria-describedby="reason-{{ $redemption->id }}-hint{{ $bag->has('reason') ? ' reason-'.$redemption->id.'-error' : '' }}"
                                              @if ($bag->has('reason')) aria-invalid="true" @endif
                                              class="block w-full max-w-3xl rounded-lg border-2 {{ $bag->has('reason') ? 'border-hold' : 'border-edge' }} bg-surface px-3 py-2 focus-visible:border-primary">{{ $bag->any() ? old('reason') : '' }}</textarea>
                                    <p id="reason-{{ $redemption->id }}-hint" class="text-sm text-muted">For example: "Regular customer, receipt checked with the merchant." 5 to 500 characters.</p>
                                    @if ($bag->has('reason'))
                                        <p id="reason-{{ $redemption->id }}-error" class="text-sm font-semibold text-hold"><span aria-hidden="true">⚑</span> {{ $bag->first('reason') }}</p>
                                    @endif
                                </div>
                                <fieldset class="space-y-1.5" @if ($bag->has('denial')) role="radiogroup" aria-invalid="true" aria-describedby="denial-{{ $redemption->id }}-error" @endif>
                                    <legend class="font-semibold">If you deny it, where does the credit go?</legend>
                                    @foreach (\App\Enums\DenialReason::cases() as $why)
                                        <label class="flex items-start gap-2 text-sm">
                                            <input type="radio" name="denial" value="{{ $why->value }}" class="mt-0.5 size-5 shrink-0 accent-primary" @checked($bag->any() && old('denial') === $why->value)>
                                            <span>{{ $why->label() }}</span>
                                        </label>
                                    @endforeach
                                    @if ($bag->has('denial'))
                                        <p id="denial-{{ $redemption->id }}-error" class="text-sm font-semibold text-hold"><span aria-hidden="true">⚑</span> {{ $bag->first('denial') }}</p>
                                    @endif
                                </fieldset>
                                {{-- Phones: two equal buttons with room between them. --}}
                                <div class="grid grid-cols-2 gap-3 sm:flex sm:flex-wrap sm:gap-2">
                                    <x-button type="submit" size="sm" aria-label="Approve {{ Money::format($redemption->amount_cents) }} at {{ $redemption->merchant->name }}">Approve</x-button>
                                    <x-button type="submit" size="sm" variant="danger" class="bg-surface" formaction="{{ route('bid-admin.deny', $redemption) }}" aria-label="Deny {{ Money::format($redemption->amount_cents) }} at {{ $redemption->merchant->name }}">Deny</x-button>
                                </div>
                                <p class="text-sm text-muted">Approve pays the merchant (one transfer, never two). Deny means no transfer, ever.</p>
                            </form>
                        @endcan
                        </div>
                    @elseif ($redemption->reviewed_by)
                        <p class="mt-4 text-sm text-muted">
                            {{ match (true) {
                                $redemption->isApproved() => 'The reserved credits were spent.',
                                $redemption->isCancelled() => $redemption->cancelledLabel().' at the counter (paid another way, not a fraud denial): the credits went back to the employee.',
                                default => 'Denied: the credits went back to the '.($redemption->decision?->credit_returned_to ?? 'employee').'.',
                            } }}
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>
        @endif
    </section>
</div>
</x-app-shell>
