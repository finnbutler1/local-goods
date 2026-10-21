@extends('layouts.app')

@section('title', 'Merchant eligibility eval · Local Goods')

@php
    use App\Services\Eligibility\EvidenceUrl;
    use App\Services\Eligibility\MerchantEligibilityMatcher;
    use App\Services\Eligibility\MerchantEvalReport;
    use App\Services\Eligibility\MerchantEvalRunner;
    use Illuminate\Support\Carbon;

    $candidateLabel = config('eligibility.candidate_label');
    $rate = fn (array $r) => $r['of'] === 0 ? 'n/a' : "{$r['hits']} of {$r['of']}";
    $pct = fn (array $r) => $r['of'] === 0 ? '' : "{$r['pct']}%";
    $ci = fn (array $r) => $r['of'] === 0 ? 'none to measure' : '95% CI '.MerchantEvalReport::ci($r['ci95']);
@endphp

@section('content')
    <div class="mx-auto max-w-6xl space-y-10 px-4 py-12 sm:px-6">
        <nav aria-label="Breadcrumb" class="text-sm font-semibold text-forest">
            <a href="{{ route('eval') }}" class="underline decoration-line decoration-2 underline-offset-4 hover:decoration-forest">
                <span aria-hidden="true">←</span> AI eval results
            </a>
        </nav>

        <header class="max-w-3xl">
            <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Merchant Eligibility Matching</p>
            <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Can the AI tell an independent business from a chain?</h1>
            <p class="mt-3 text-lg text-muted">
                Finn hand-labeled 60 real businesses inside the Golden Triangle BID against the independence rule, with a public source for each,
                and locked the labels before the AI saw them. The AI saw only each business's name, address, category and license activity.
                <strong>Dev</strong> cases (40) may be used to improve the prompt; <strong>holdout</strong> cases (20) are scored once per prompt version
                and never used to change it. The two are always reported separately.
            </p>
            <p class="mt-3 text-muted">
                <span class="font-semibold text-ink">What the AI knows:</span> {{ MerchantEligibilityMatcher::KNOWLEDGE_NOTICE }}
            </p>
            <p class="mt-4 rounded-2xl border-2 border-amber bg-paper p-4 font-semibold">
                {{ $candidateLabel }} None of these businesses has joined Local Goods.
            </p>
        </header>

        <section aria-labelledby="policy-heading" class="relative overflow-hidden rounded-3xl bg-forest p-6 text-cream sm:p-8">
            <h2 id="policy-heading" class="font-sans text-sm font-semibold uppercase tracking-widest text-amber">How to read these numbers</h2>
            <p class="mt-2 max-w-3xl font-display text-2xl font-semibold sm:text-3xl">{{ config('eligibility.policy') }}</p>
            <p class="mt-4 max-w-3xl">
                An AI “eligible” is only a recommendation: the BID admin sees “AI recommends eligible — confirm before approving”, and no business is approved until a person presses Approve.
                @php $devRecall = $latest['splits']['dev']['summary']['recall'] ?? null; @endphp
                @if ($devRecall && $devRecall['of'] > 0)
                    Without web access, it recognized only {{ $devRecall['hits'] }} of {{ $devRecall['of'] }} independent businesses on dev; most small independents come back undetermined and go to a person.
                @endif
                Giving it a vetted web search is future work, and would need a new prompt version and a fresh unseen holdout.
            </p>
            <p class="mt-3 max-w-3xl text-sm text-sage">Final prompt: <code class="text-cream">{{ config('eligibility.frozen_prompt_version') }}</code>, frozen before the holdout was run once.</p>
        </section>

        @if ($lock)
            <p class="max-w-3xl text-sm text-muted">
                <strong class="text-ink">Locked before the AI saw them:</strong> the {{ $lock['row_count'] }} labels were made in commit
                <a href="{{ config('fraud.eval_repo_url') }}/commit/{{ $lock['labeling_commit'] }}" class="font-mono underline underline-offset-4">{{ substr($lock['labeling_commit'], 0, 7) }}</a>
                and locked on {{ Carbon::parse($lock['locked_at'])->format('M j, Y') }} with a SHA-256 of every row
                (combined <code class="break-all">{{ substr($lock['combined_sha256'], 0, 12) }}</code>). The eval refuses to run if any label changes.
            </p>
        @endif

        @if (! $showNames)
            <x-card tone="cream" class="max-w-3xl">
                <h2 class="text-xl font-semibold text-forest">Totals only on this page</h2>
                <p class="mt-2">
                    These are real businesses that have not joined Local Goods, so this public page shows totals only: no names, notes or per-business results.
                    Signed-in reviewers with their own link see every disagreement with the hand label's note and the AI's reasoning.
                </p>
            </x-card>
        @endif

        @if ($latest === null)
            <p class="rounded-2xl bg-paper p-5 ring-1 ring-line/70">
                No results yet. Run <code>php artisan merchant:eval</code> to create them.
            </p>
        @else
            <p class="max-w-3xl text-sm text-muted">
                Current prompt version <code>{{ $promptVersion }}</code>.
                Prompt versions that have seen the holdout:
                @if ($latest['holdout_seen_by'] === []) none yet. @else @foreach ($latest['holdout_seen_by'] as $v)<code>{{ $v }}</code>@if (! $loop->last), @endif @endforeach. @endif
            </p>

            @foreach (MerchantEvalRunner::SPLITS as $split => $title)
                @php $run = $latest['splits'][$split] ?? null; @endphp
                <section aria-labelledby="{{ $split }}-heading" class="space-y-4">
                    <h2 id="{{ $split }}-heading" class="text-3xl font-semibold text-forest">{{ $title }}</h2>

                    @if ($run === null)
                        <p class="rounded-2xl bg-paper p-5 ring-1 ring-line/70">
                            Not run yet. @if ($split === 'holdout') The holdout is scored once, after the prompt is final. @endif
                        </p>
                    @else
                        @php $s = $run['summary']; @endphp
                        <p class="text-sm text-muted">
                            {{ $s['cases'] }} cases · run {{ Carbon::parse($run['run_at'])->format('M j, Y') }} · model <code>{{ $run['model'] }}</code> ·
                            prompt <code>{{ $run['prompt_version'] }}</code>@if ($run['prompt_version'] !== $promptVersion) (not the current prompt)@endif
                        </p>

                        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <x-card padding="p-4 sm:p-5">
                                <dt class="text-sm font-semibold text-muted">Eligible yes/no agreement</dt>
                                <dd class="tabular mt-1 font-display text-3xl font-semibold text-forest">{{ $rate($s['agreement']) }}</dd>
                                <dd class="text-sm text-muted">{{ $pct($s['agreement']) }}, {{ $ci($s['agreement']) }}</dd>
                            </x-card>
                            <x-card padding="p-4 sm:p-5" tone="cream">
                                <dt class="text-sm font-semibold text-muted">Baseline: always answer “not eligible”</dt>
                                <dd class="tabular mt-1 font-display text-3xl font-semibold text-forest">{{ $rate($s['baseline_always_not_eligible']) }}</dd>
                                <dd class="text-sm text-muted">{{ $pct($s['baseline_always_not_eligible']) }}. Most labeled businesses are not independent, so agreement alone flatters.</dd>
                            </x-card>
                            <x-card padding="p-4 sm:p-5">
                                <dt class="text-sm font-semibold text-muted">Independent businesses the AI found</dt>
                                <dd class="tabular mt-1 font-display text-3xl font-semibold text-forest">{{ $rate($s['recall']) }}</dd>
                                <dd class="text-sm text-muted">Of Finn's independent ones, the AI called eligible. {{ $ci($s['recall']) }}</dd>
                            </x-card>
                            <x-card padding="p-4 sm:p-5">
                                <dt class="text-sm font-semibold text-muted">AI “eligible” calls that were right</dt>
                                <dd class="tabular mt-1 font-display text-3xl font-semibold text-forest">{{ $rate($s['precision']) }}</dd>
                                <dd class="text-sm text-muted">Of the AI's eligible calls, Finn labeled independent. {{ $ci($s['precision']) }}</dd>
                            </x-card>
                        </dl>

                        @if (isset($s['triage']))
                            <div>
                                <h3 class="text-xl font-semibold text-forest">Triage</h3>
                                <dl class="mt-2 grid gap-3 sm:grid-cols-3">
                                    <x-card padding="p-4 sm:p-5">
                                        <dt class="text-sm font-semibold text-muted">Chains and franchises correctly called not eligible</dt>
                                        <dd class="tabular mt-1 font-display text-3xl font-semibold text-forest">{{ $rate($s['triage']['chains_not_eligible']) }}</dd>
                                        <dd class="text-sm text-muted">{{ $pct($s['triage']['chains_not_eligible']) }}, {{ $ci($s['triage']['chains_not_eligible']) }}</dd>
                                    </x-card>
                                    <x-card padding="p-4 sm:p-5">
                                        <dt class="text-sm font-semibold text-muted">Chains and franchises wrongly called eligible</dt>
                                        <dd class="tabular mt-1 font-display text-3xl font-semibold text-forest">{{ $rate($s['triage']['chains_called_eligible']) }}</dd>
                                        <dd class="text-sm text-muted">{{ $pct($s['triage']['chains_called_eligible']) }}, {{ $ci($s['triage']['chains_called_eligible']) }}. The costliest mistake; a person still decides.</dd>
                                    </x-card>
                                    <x-card padding="p-4 sm:p-5">
                                        <dt class="text-sm font-semibold text-muted">All cases routed to human review</dt>
                                        <dd class="tabular mt-1 font-display text-3xl font-semibold text-forest">{{ $rate($s['triage']['routed_to_review']) }}</dd>
                                        <dd class="text-sm text-muted">{{ $pct($s['triage']['routed_to_review']) }}, {{ $ci($s['triage']['routed_to_review']) }}</dd>
                                    </x-card>
                                </dl>
                            </div>
                        @endif

                        <ul class="space-y-1 text-sm">
                            <li><span class="font-semibold">Exact classification match:</span> {{ MerchantEvalReport::rate($s['class_match']) }}</li>
                            <li><span class="font-semibold">AI said undetermined on a case Finn could decide:</span> {{ MerchantEvalReport::rate($s['undetermined']['ai_undetermined_on_decided']) }}</li>
                            <li><span class="font-semibold">Cases Finn labeled undetermined:</span>
                                @if ($s['undetermined']['human_undetermined'] === 0)
                                    none, so “did the AI also say undetermined instead of guessing?” can't be measured on this set.
                                @else
                                    the AI also said undetermined on {{ MerchantEvalReport::rate($s['undetermined']['ai_also_undetermined']) }}.
                                @endif
                            </li>
                            <li><span class="font-semibold">Flagged for a person by the AI or the confidence rule:</span> {{ $s['needs_human_review'] }} of {{ $s['cases'] }}</li>
                        </ul>

                        <div class="grid gap-6 lg:grid-cols-2">
                            <div class="scroll-region" role="region" tabindex="0" aria-label="Confusion matrix ({{ $title }}) table">
                                <table class="tabular w-full rounded-2xl bg-paper text-left ring-1 ring-line/70">
                                    <caption class="px-4 pt-3 text-left text-sm font-semibold text-muted">Confusion matrix ({{ $title }}): rows are Finn's labels, columns the AI's answers</caption>
                                    <thead>
                                        <tr class="bg-cream-deep/60 text-sm text-muted">
                                            <th scope="col" class="px-4 py-2">Finn</th>
                                            @foreach (MerchantEvalReport::OUTCOME_NAMES as $name)<th scope="col" class="px-4 py-2">AI: {{ $name }}</th>@endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($s['matrix'] as $label => $row)
                                            <tr class="border-t border-line/70">
                                                <th scope="row" class="px-4 py-2 font-semibold">{{ MerchantEvalReport::LABEL_NAMES[$label] }}</th>
                                                @foreach ($row as $n)<td class="px-4 py-2">{{ $n }}</td>@endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="scroll-region" role="region" tabindex="0" aria-label="Not independent, by reason ({{ $title }}) table">
                                <table class="tabular w-full rounded-2xl bg-paper text-left ring-1 ring-line/70">
                                    <caption class="px-4 pt-3 text-left text-sm font-semibold text-muted">Not independent, by the reason Finn gave ({{ $title }})</caption>
                                    <thead>
                                        <tr class="bg-cream-deep/60 text-sm text-muted">
                                            <th scope="col" class="px-4 py-2">Reason</th>
                                            <th scope="col" class="px-4 py-2">Cases</th>
                                            <th scope="col" class="px-4 py-2">AI: not eligible</th>
                                            <th scope="col" class="px-4 py-2">AI: same reason</th>
                                            <th scope="col" class="px-4 py-2">AI: undetermined</th>
                                            <th scope="col" class="px-4 py-2">AI: eligible</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($s['by_fail_reason'] as $reason => $r)
                                            <tr class="border-t border-line/70">
                                                <th scope="row" class="px-4 py-2 font-semibold">{{ MerchantEvalReport::CLASS_NAMES[$reason] }}</th>
                                                <td class="px-4 py-2">{{ $r['cases'] }}</td>
                                                <td class="px-4 py-2">{{ $rate($r['not_eligible']) }}</td>
                                                <td class="px-4 py-2">{{ $rate($r['same_reason']) }}</td>
                                                <td class="px-4 py-2">{{ $r['undetermined'] }}</td>
                                                <td class="px-4 py-2">{{ $r['called_eligible'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        @isset($s['category_disagreements'])
                            <div class="max-w-3xl">
                                <h3 class="text-xl font-semibold text-forest">Category disagreements ({{ $s['category_disagreements'] }})</h3>
                                <p class="mt-1">{{ MerchantEvalReport::CATEGORY_NOTE }}</p>
                                @if ($showNames && $s['category_disagreements'] > 0)
                                    <ul class="mt-2 list-disc space-y-0.5 pl-5 text-sm">
                                        @foreach (array_filter($run['results'], fn ($r) => MerchantEvalReport::isCategoryDisagreement($r)) as $r)
                                            <li>{{ $r['candidate_id'] }} · {{ $r['name'] }}: Finn {{ MerchantEvalReport::CLASS_NAMES[$r['fail_reason']] }}, AI {{ MerchantEvalReport::CLASS_NAMES[$r['ai_classification']] }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endisset

                        @if ($showNames)
                            @php $disagreements = array_values(array_filter($run['results'], fn ($r) => ! $r['class_match'])); @endphp
                            <div>
                                <h3 class="text-xl font-semibold text-forest">Disagreements ({{ count($disagreements) }})</h3>
                                <p class="mt-1 text-sm text-muted">Every case where the AI's classification differs from Finn's. “Eligible answer differs” means the yes/no answer differs too.</p>
                                <ul class="mt-3 space-y-3">
                                    @foreach ($disagreements as $r)
                                        <x-card as="li" @class(['border-l-4', 'border-l-hold' => ! $r['agree'], 'border-l-amber' => $r['agree']])>
                                            <div class="flex flex-wrap items-start justify-between gap-2">
                                                <div>
                                                    <p class="font-display text-lg font-semibold">{{ $r['candidate_id'] }} · {{ $r['name'] }}</p>
                                                    <p class="text-sm text-muted">{{ ucfirst($r['category']) }}@if ($r['business_activity']) · license: {{ $r['business_activity'] }}@endif</p>
                                                    <p class="mt-1 text-xs font-semibold uppercase tracking-wider text-amber-ink">{{ $candidateLabel }}</p>
                                                </div>
                                                @unless ($r['agree'])<x-badge tone="hold">Eligible answer differs</x-badge>@endunless
                                            </div>
                                            <dl class="mt-3 grid gap-3 border-t border-line/70 pt-3 md:grid-cols-2">
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-widest text-muted">Finn</dt>
                                                    <dd class="mt-1"><span class="font-semibold">{{ MerchantEvalReport::LABEL_NAMES[$r['my_label']] }}@if ($r['fail_reason']) ({{ MerchantEvalReport::CLASS_NAMES[$r['fail_reason']] }})@endif.</span> {{ $r['my_note'] }}
                                                        @if ($r['evidence_url'])<a href="{{ EvidenceUrl::withoutTracking($r['evidence_url']) }}" class="underline underline-offset-4" rel="noopener" target="_blank">Source<span class="sr-only"> for {{ $r['name'] }} (opens in a new tab)</span></a>@endif
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-widest text-muted">AI</dt>
                                                    <dd class="mt-1"><span class="font-semibold">{{ MerchantEvalReport::CLASS_NAMES[$r['ai_classification']] }}, confidence {{ round(100 * $r['confidence']) }}%.</span> {{ $r['reasoning'] }}@if (! empty($r['redacted']))<span class="mt-1 block text-sm text-muted">{{ $r['redacted'] }}</span>@endif</dd>
                                                </div>
                                            </dl>
                                        </x-card>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endif
                </section>
            @endforeach

            <section aria-labelledby="history-heading">
                <h2 id="history-heading" class="text-2xl font-semibold text-forest">Run history</h2>
                <div class="mt-3 scroll-region" role="region" tabindex="0" aria-label="Every merchant eval run table">
                    <table class="tabular w-full min-w-2xl rounded-2xl bg-paper text-left ring-1 ring-line/70">
                        <caption class="sr-only">Every merchant eval run, oldest first</caption>
                        <thead>
                            <tr class="bg-cream-deep/60 text-sm text-muted">
                                <th scope="col" class="px-4 py-2">Run</th>
                                <th scope="col" class="px-4 py-2">Split</th>
                                <th scope="col" class="px-4 py-2">Prompt</th>
                                <th scope="col" class="px-4 py-2">Agreement</th>
                                <th scope="col" class="px-4 py-2">Independent found</th>
                                <th scope="col" class="px-4 py-2">Eligible calls right</th>
                                <th scope="col" class="px-4 py-2">Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($latest['history'] as $h)
                                <tr class="border-t border-line/70">
                                    <td class="px-4 py-2">{{ Carbon::parse($h['run_at'])->format('M j, Y g:i A') }}</td>
                                    <td class="px-4 py-2">{{ MerchantEvalRunner::SPLITS[$h['split']] ?? $h['split'] }}</td>
                                    <td class="px-4 py-2"><code>{{ $h['prompt_version'] }}</code></td>
                                    <td class="px-4 py-2">{{ $h['agreement_pct'] }}% of {{ $h['cases'] }}</td>
                                    <td class="px-4 py-2">{{ $rate($h['recall']) }}</td>
                                    <td class="px-4 py-2">{{ $rate($h['precision']) }}</td>
                                    <td class="px-4 py-2 text-sm">{{ $h['note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
