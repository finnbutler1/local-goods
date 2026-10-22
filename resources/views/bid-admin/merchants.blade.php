@php
    use App\Models\MerchantReviewDecision;
    use App\Services\Eligibility\MerchantEligibilityMatcher;
    use App\Services\Eligibility\MerchantEvalReport;

    $candidateLabel = config('eligibility.candidate_label');
    $confidenceWords = fn (float $c) => $c >= 0.9 ? 'high' : ($c >= 0.7 ? 'medium' : 'low');
    $classTone = fn (string $class) => match ($class) {
        'independent' => 'ok',
        'undetermined' => 'amber',
        default => 'hold',
    };
@endphp

<x-app-shell title="Merchant eligibility" active="merchants"
             :context="$bid->name.' BID · '.$bid->city">
<div class="space-y-8">
    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">{{ $bid->name }} BID · {{ $bid->city }}</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Merchant Eligibility Matching</h1>
        <p class="mt-3 text-lg text-muted">
            Real businesses inside the BID, taken from DC's public business licenses. For each one the AI says whether it
            looks independent under the rule, how sure it is, and why. A person at the BID makes the decision.
        </p>
        <p class="mt-4 rounded-2xl border-2 border-amber bg-paper p-4 font-semibold">
            {{ $candidateLabel }} Every business on this page is a candidate only. Approving records a decision; it does not sign the business up or move any money.
        </p>
        <p class="mt-3 rounded-2xl bg-forest-tint p-4">
            <span class="font-semibold">{{ config('eligibility.policy') }}</span>
            Nothing here is approved until a BID admin presses Approve, and every decision is logged with who and when.
        </p>
        <p class="mt-3 text-sm text-muted">
            <span class="font-semibold text-ink">What the AI knows:</span> {{ MerchantEligibilityMatcher::KNOWLEDGE_NOTICE }}
            It saw only each business's name, address, category and license activity.
        </p>
        <p class="mt-3">
            <a href="{{ route('eval.merchants') }}" class="font-semibold text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">
                How often does the AI agree with hand labels? See the merchant eval <span aria-hidden="true">→</span>
            </a>
        </p>
    </header>

    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <x-card padding="p-4 sm:p-5">
            <dt class="text-sm font-semibold text-muted">Assessed by the AI</dt>
            <dd class="tabular mt-1 font-display text-4xl font-semibold text-forest">{{ $totals['assessed'] }}</dd>
            <dd class="text-sm text-muted">of {{ $totals['candidates'] }} candidates</dd>
        </x-card>
        <x-card padding="p-4 sm:p-5">
            <dt class="text-sm font-semibold text-muted">AI flagged for a person</dt>
            <dd class="tabular mt-1 font-display text-4xl font-semibold text-forest">{{ $totals['needs_review'] }}</dd>
        </x-card>
        <x-card padding="p-4 sm:p-5">
            <dt class="text-sm font-semibold text-muted">Waiting for a decision</dt>
            <dd class="tabular mt-1 font-display text-4xl font-semibold text-forest">{{ $totals['by_status']['waiting'] }}</dd>
        </x-card>
        <x-card padding="p-4 sm:p-5">
            <dt class="text-sm font-semibold text-muted">Approved · rejected · needs research</dt>
            <dd class="tabular mt-1 font-display text-2xl font-semibold text-forest">
                {{ $totals['by_status']['approve'] }} · {{ $totals['by_status']['reject'] }} · {{ $totals['by_status']['needs_research'] }}
            </dd>
        </x-card>
    </dl>

    <section aria-labelledby="by-class-heading">
        <h2 id="by-class-heading" class="text-2xl font-semibold text-forest">The AI's answers</h2>
        <ul class="mt-3 flex flex-wrap gap-2">
            @foreach ($totals['by_classification'] as $class => $count)
                <li><x-badge :tone="$classTone($class)">{{ MerchantEvalReport::CLASS_NAMES[$class] }}: {{ $count }}</x-badge></li>
            @endforeach
        </ul>
    </section>

    @if (! $showNames)
        <x-card tone="cream">
            <h2 class="text-xl font-semibold text-forest">Business names are hidden on this account</h2>
            <p class="mt-2">
                These are real businesses that have not joined Local Goods, and this shared access link is public, so it shows totals only.
                Named reviewers see each business with the AI's reasoning. Ask the project owner for your own link to see them.
            </p>
        </x-card>
    @elseif ($totals['assessed'] === 0)
        <x-card tone="cream">
            <p>The AI has not assessed any candidates yet. They appear here after <code>php artisan merchant:eval</code> runs.</p>
        </x-card>
    @else
        <section aria-labelledby="queue-heading">
            <h2 id="queue-heading" class="text-2xl font-semibold text-forest">Candidates</h2>
            <p class="mt-1 text-sm text-muted">Waiting first, with the ones the AI flagged for a person at the top.</p>
            <ul class="mt-3 space-y-3">
                @foreach ($items as $item)
                    @php
                        $c = $item['candidate'];
                        $a = $item['assessment'];
                        $latest = $item['latest'];
                    @endphp
                    <x-card as="li" id="candidate-{{ $item['id'] }}" @class(['scroll-mt-24 border-l-4', 'border-l-amber' => ! $latest, 'border-l-ok' => $latest?->decision === 'approve', 'border-l-hold' => $latest?->decision === 'reject', 'border-l-muted' => $latest?->decision === 'needs_research'])>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-display text-xl font-semibold">{{ $c['name'] }}</p>
                                <p class="text-sm text-muted">{{ $c['address'] }} · {{ ucfirst($c['category']) }}@if ($c['business_activity']) · license: {{ $c['business_activity'] }}@endif</p>
                                <p class="mt-1 text-xs font-semibold uppercase tracking-wider text-amber-ink">{{ $candidateLabel }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($a['eligible'])
                                    <x-badge tone="amber" :wrap="true">AI recommends eligible — confirm before approving</x-badge>
                                @else
                                    <x-badge :tone="$classTone($a['classification'])">AI: {{ MerchantEvalReport::CLASS_NAMES[$a['classification']] }}</x-badge>
                                @endif
                                @if ($a['needs_human_review'])<x-badge tone="neutral">Flagged for a person</x-badge>@endif
                                <x-badge :tone="$latest ? 'forest' : 'neutral'">{{ $latest ? $latest->label() : 'Waiting' }}</x-badge>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-4 border-t border-line/70 pt-4 md:grid-cols-3">
                            <div class="md:col-span-2">
                                <h3 class="font-sans text-xs font-semibold uppercase tracking-widest text-muted">AI reasoning</h3>
                                <p class="mt-1">{{ $a['reasoning'] }}</p>
                                @if (! empty($a['redacted']))<p class="mt-1 text-sm text-muted">{{ $a['redacted'] }}</p>@endif
                                <p class="mt-2 text-sm text-muted">{{ MerchantEligibilityMatcher::KNOWLEDGE_NOTICE }}</p>
                            </div>
                            <dl class="space-y-1 text-sm">
                                <div><dt class="inline font-semibold">AI recommendation:</dt> <dd class="inline">{{ $a['eligible'] ? 'eligible — confirm before approving' : 'not eligible' }}</dd></div>
                                <div><dt class="inline font-semibold">Confidence:</dt> <dd class="inline tabular">{{ round(100 * $a['confidence']) }}% ({{ $confidenceWords($a['confidence']) }})</dd></div>
                                <div><dt class="inline font-semibold">Assessed by:</dt> <dd class="inline">{{ $a['assessed_by'] === 'fallback' ? 'nobody: the AI check failed' : $a['assessed_by'] }}, prompt {{ $a['prompt_version'] }}</dd></div>
                                <div><dt class="inline font-semibold">Source:</dt> <dd class="inline"><a href="{{ $c['source_url'] }}" class="underline underline-offset-4" rel="noopener" target="_blank">DC license record<span class="sr-only"> for {{ $c['name'] }} (opens in a new tab)</span></a></dd></div>
                            </dl>
                        </div>

                        @can('decide-merchant-eligibility')
                            <form method="POST" action="{{ route('bid-admin.merchants.decide', $item['id']) }}" data-busy="Saving your decision…" class="mt-5 rounded-xl bg-cream-deep p-4">
                                @csrf
                                <label for="note-{{ $item['id'] }}" class="text-sm font-semibold">Note (optional, saved with your decision)</label>
                                <textarea id="note-{{ $item['id'] }}" name="note" rows="2" maxlength="1000"
                                          class="mt-1 block w-full rounded-lg border border-line bg-paper p-2"></textarea>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <x-button type="submit" size="sm" name="decision" value="approve" aria-label="Approve {{ $c['name'] }} as eligible">Approve</x-button>
                                    <x-button type="submit" size="sm" variant="danger" class="bg-paper" name="decision" value="reject" aria-label="Reject {{ $c['name'] }}">Reject</x-button>
                                    <x-button type="submit" size="sm" variant="secondary" name="decision" value="needs_research" aria-label="Needs research: {{ $c['name'] }}">Needs research</x-button>
                                </div>
                            </form>
                        @else
                            <p class="mt-5 rounded-xl bg-cream-deep p-4 text-sm">Read-only: a BID admin approves, rejects or asks for more research here.</p>
                        @endcan

                        @if ($item['decisions']->isNotEmpty())
                            <div class="mt-4">
                                <h3 class="font-sans text-xs font-semibold uppercase tracking-widest text-muted">Decision log</h3>
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
                    </x-card>
                @endforeach
            </ul>
        </section>
    @endif
</div>
</x-app-shell>
