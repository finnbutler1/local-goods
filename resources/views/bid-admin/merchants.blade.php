@php
    use App\Models\MerchantReviewDecision;
    use App\Services\Eligibility\MerchantEligibilityMatcher;
    use App\Services\Eligibility\MerchantEvalReport;

    $candidateLabel = config('eligibility.candidate_label');
    $confidenceWords = fn (float $c) => $c >= 0.9 ? 'high' : ($c >= 0.7 ? 'medium' : 'low');
    $classTone = fn (string $class) => match ($class) {
        'independent' => 'ok',
        'undetermined' => 'accent',
        default => 'hold',
    };
@endphp

<x-app-shell title="Merchant Eligibility" active="merchants"
             :context="$bid->name.' BID · '.$bid->city">
<div class="admin-desk space-y-12">
    <header>
        <x-station-sign as="p">{{ $bid->name }} BID · {{ $bid->city }}</x-station-sign>
        <h1 class="page-title">Merchant Eligibility</h1>
        <p class="mt-3 text-2xl font-bold tracking-tight text-ink">Merchant Eligibility Matching</p>
        <p class="page-lede">
            Real businesses inside the BID, taken from DC's public business licenses. For each one the AI says whether it
            looks independent under the rule, how sure it is, and why. A person at the BID makes the decision.
        </p>
        <p class="mt-6 max-w-[52rem] border-2 border-frame bg-surface px-4 py-3 font-semibold">
            {{ $candidateLabel }} Every business on this page is a candidate only. Approving records a decision; it does not sign the business up or move any money.
        </p>
        <p class="mt-3 max-w-[52rem] border-t-[3px] border-primary bg-primary-tint px-4 py-3">
            <span class="font-semibold">{{ config('eligibility.policy') }}</span>
            Nothing here is approved until a BID admin presses Approve, and every decision is logged with who and when.
        </p>
        <p class="mt-4 max-w-[52rem] text-sm text-muted">
            <span class="font-semibold text-ink">What the AI knows:</span> {{ MerchantEligibilityMatcher::KNOWLEDGE_NOTICE }}
            It saw only each business's name, address, category and license activity.
        </p>
        <p class="mt-3">
            <a href="{{ route('eval.merchants') }}" class="font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover">
                How often does the AI agree with hand labels? See the merchant eval <span aria-hidden="true">→</span>
            </a>
        </p>
    </header>

    <dl class="grid grid-cols-2 gap-x-6 gap-y-10 lg:grid-cols-4">
        <div class="rule-top">
            <dt class="figure-label">Assessed by the AI</dt>
            <dd class="money mt-2 text-4xl sm:text-5xl">{{ $totals['assessed'] }}</dd>
            <dd class="mt-1 text-sm text-muted">of {{ $totals['candidates'] }} candidates</dd>
        </div>
        <div class="rule-top">
            <dt class="figure-label">AI flagged for a person</dt>
            <dd class="money mt-2 text-4xl sm:text-5xl">{{ $totals['needs_review'] }}</dd>
        </div>
        <div class="rule-top">
            <dt class="figure-label">Waiting for a decision</dt>
            <dd class="money mt-2 text-4xl sm:text-5xl text-primary">{{ $totals['by_status']['waiting'] }}</dd>
        </div>
        <div class="rule-top">
            <dt class="figure-label">Approved · rejected · needs research</dt>
            <dd class="money mt-2 text-4xl sm:text-5xl">
                {{ $totals['by_status']['approve'] }} · {{ $totals['by_status']['reject'] }} · {{ $totals['by_status']['needs_research'] }}
            </dd>
        </div>
    </dl>

    <section aria-labelledby="by-class-heading" class="rule-heavy">
        <h2 id="by-class-heading" class="stop-subtitle">The AI's answers</h2>
        <ul class="mt-4 grid max-w-[52rem] border-t border-ink sm:grid-cols-2">
            @foreach ($totals['by_classification'] as $class => $count)
                <li class="tabular border-b border-line py-2 font-semibold sm:odd:pr-6 sm:even:pl-6">{{ MerchantEvalReport::CLASS_NAMES[$class] }}: {{ $count }}</li>
            @endforeach
        </ul>
    </section>

    @if (! $showNames)
        <section class="rule-heavy">
            <h2 class="stop-subtitle">Business names are hidden on this account</h2>
            <p class="mt-2">
                These are real businesses that have not joined Local Goods, and this shared access link is public, so it shows totals only.
                Named reviewers see each business with the AI's reasoning. Ask the project owner for your own link to see them.
            </p>
        </section>
    @elseif ($totals['assessed'] === 0)
        <div class="ledger-note">
            <p>The AI has not assessed any candidates yet. They appear here after <code>php artisan merchant:eval</code> runs.</p>
        </div>
    @else
        <section aria-labelledby="queue-heading">
            <h2 id="queue-heading" class="stop-subtitle text-2xl">Candidates</h2>
            <p class="mt-1 text-sm text-muted">Waiting first, with the ones the AI flagged for a person at the top.</p>
            <ul class="entry-list mt-4">
                @foreach ($items as $item)
                    @php
                        $c = $item['candidate'];
                        $a = $item['assessment'];
                        $latest = $item['latest'];
                    @endphp
                    <li id="candidate-{{ $item['id'] }}" class="scroll-mt-24">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-xl font-bold tracking-tight">{{ $c['name'] }}</p>
                                <p class="text-sm text-muted">{{ $c['address'] }} · {{ ucfirst($c['category']) }}@if ($c['business_activity']) · license: {{ $c['business_activity'] }}@endif</p>
                                <p class="mt-1 text-xs font-semibold uppercase tracking-wider text-accent-ink">{{ $candidateLabel }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($a['eligible'])
                                    <x-badge tone="accent" :wrap="true">AI recommends eligible — confirm before approving</x-badge>
                                @else
                                    <x-badge :tone="$classTone($a['classification'])">AI: {{ MerchantEvalReport::CLASS_NAMES[$a['classification']] }}</x-badge>
                                @endif
                                @if ($a['needs_human_review'])<x-badge tone="neutral">Flagged for a person</x-badge>@endif
                                <x-badge :tone="$latest ? 'primary' : 'neutral'">{{ $latest ? $latest->label() : 'Waiting' }}</x-badge>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-x-8 gap-y-6 md:grid-cols-12">
                            <div class="md:col-span-8">
                                <h3 class="entry-label">AI reasoning</h3>
                                <p class="mt-1.5 max-w-[65ch] leading-relaxed">{{ $a['reasoning'] }}</p>
                                @if (! empty($a['redacted']))<p class="mt-1 text-sm text-muted">{{ $a['redacted'] }}</p>@endif
                                <p class="mt-2 text-sm text-muted">{{ MerchantEligibilityMatcher::KNOWLEDGE_NOTICE }}</p>
                            </div>
                            <dl class="space-y-1 border-t border-ink pt-2 text-sm md:col-span-4">
                                <div><dt class="inline font-semibold">AI recommendation:</dt> <dd class="inline">{{ $a['eligible'] ? 'eligible — confirm before approving' : 'not eligible' }}</dd></div>
                                <div><dt class="inline font-semibold">Confidence:</dt> <dd class="inline tabular">{{ round(100 * $a['confidence']) }}% ({{ $confidenceWords($a['confidence']) }})</dd></div>
                                <div><dt class="inline font-semibold">Assessed by:</dt> <dd class="inline">{{ $a['assessed_by'] === 'fallback' ? 'nobody: the AI check failed' : $a['assessed_by'] }}, prompt {{ $a['prompt_version'] }}</dd></div>
                                <div><dt class="inline font-semibold">Source:</dt> <dd class="inline"><a href="{{ $c['source_url'] }}" class="tap-target underline underline-offset-4" rel="noopener" target="_blank">DC license record<span class="sr-only"> for {{ $c['name'] }} (opens in a new tab)</span></a></dd></div>
                            </dl>
                        </div>

                        @can('decide-merchant-eligibility')
                            <form method="POST" action="{{ route('bid-admin.merchants.decide', $item['id']) }}" data-busy="Saving your decision…" class="ledger-note mt-5">
                                @csrf
                                <label for="note-{{ $item['id'] }}" class="text-sm font-semibold">Note (optional, saved with your decision)</label>
                                <textarea id="note-{{ $item['id'] }}" name="note" rows="2" maxlength="1000"
                                          class="mt-1 block w-full max-w-3xl rounded-lg border-2 border-edge bg-surface px-3 py-2 focus-visible:border-primary"></textarea>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <x-button type="submit" size="sm" name="decision" value="approve" aria-label="Approve {{ $c['name'] }} as eligible">Approve</x-button>
                                    <x-button type="submit" size="sm" variant="danger" class="bg-surface" name="decision" value="reject" aria-label="Reject {{ $c['name'] }}">Reject</x-button>
                                    <x-button type="submit" size="sm" variant="secondary" name="decision" value="needs_research" aria-label="Needs research: {{ $c['name'] }}">Needs research</x-button>
                                </div>
                            </form>
                        @else
                            <p class="ledger-note mt-5 text-sm">Read-only: a BID admin approves, rejects or asks for more research here.</p>
                        @endcan

                        @if ($item['decisions']->isNotEmpty())
                            <div class="mt-4">
                                <h3 class="entry-label">Decision log</h3>
                                <ol class="mt-1 space-y-1 text-sm">
                                    @foreach ($item['decisions'] as $d)
                                        <li>
                                            <span class="font-semibold">{{ $d->label() }}</span>
                                            by {{ $d->user->name }},
                                            <time datetime="{{ $d->created_at->toIso8601String() }}">{{ $d->created_at->setTimezone('America/New_York')->format('M j, Y g:i A') }}</time>@if ($d->note): “{{ $d->note }}”@endif
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
</x-app-shell>
