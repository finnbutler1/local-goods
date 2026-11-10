@extends('layouts.adaptive')

@section('title', 'Merchant eligibility eval · Local Goods')
@section('active', 'merchant-eval')

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
    <div class="wrap pt-6 pb-6">
        <nav aria-label="Breadcrumb" class="text-sm font-semibold text-primary">
            <a href="{{ route('eval') }}" class="tap-target underline decoration-line decoration-2 underline-offset-4 hover:decoration-primary">
                <span aria-hidden="true">←</span> AI eval results
            </a>
        </nav>

        <header class="mt-6">
            <x-station-sign as="p">Merchant Eligibility Matching</x-station-sign>
            <h1 class="page-title">Can the AI tell an independent business from a chain?</h1>
            <p class="page-lede max-w-[46rem]">
                Finn hand-labeled 60 real businesses inside the Golden Triangle BID against the independence rule, with a public source for each,
                and locked the labels before the AI saw them. The AI saw only each business's name, address, category and license activity.
                <strong>Dev</strong> cases (40) may be used to improve the prompt; <strong>holdout</strong> cases (20) are scored once per prompt version
                and never used to change it. The two are always reported separately.
            </p>
            <p class="mt-4 max-w-[46rem] text-muted">
                <span class="font-semibold text-ink">What the AI knows:</span> {{ MerchantEligibilityMatcher::KNOWLEDGE_NOTICE }}
            </p>
            <p class="mt-6 max-w-[46rem] rounded-lg border-2 border-ink bg-surface px-4 py-3 font-bold">
                {{ $candidateLabel }} None of these businesses has joined Local Goods.
            </p>
        </header>

        @php $stop = 0; @endphp
        <div class="stop-line stop-line-dense">
        <section aria-labelledby="policy-heading" class="stop">
            @php $stop++; @endphp
            <x-stop-number :n="$stop" />
            <x-station-sign heading-id="policy-heading">How to read these numbers</x-station-sign>
            <p class="mt-6 max-w-[30ch] font-display text-2xl leading-[1.15] font-extrabold tracking-[-0.025em] text-primary sm:text-4xl lg:col-span-9">{{ config('eligibility.policy') }}</p>
            <p class="mt-5 max-w-[46rem] lg:col-span-8">
                An AI “eligible” is only a recommendation: the BID admin sees “AI recommends eligible — confirm before approving”, and no business is approved until a person presses Approve.
                @php $devRecall = $latest['splits']['dev']['summary']['recall'] ?? null; @endphp
                @if ($devRecall && $devRecall['of'] > 0)
                    Without web access, it recognized only {{ $devRecall['hits'] }} of {{ $devRecall['of'] }} independent businesses on dev; most small independents come back undetermined and go to a person.
                @endif
                Giving it a vetted web search is future work, and would need a new prompt version and a fresh unseen holdout.
            </p>
            <p class="mt-3 max-w-[46rem] text-sm text-muted lg:col-span-8">Final prompt: <code class="text-ink">{{ config('eligibility.frozen_prompt_version') }}</code>, frozen before the holdout was run once.</p>

        @if ($lock)
            <p class="rule-top mt-6 max-w-[46rem] text-sm text-muted lg:col-span-8">
                <strong class="text-ink">Locked before the AI saw them:</strong> the {{ $lock['row_count'] }} labels were made in commit
                <a href="{{ config('fraud.eval_repo_url') }}/commit/{{ $lock['labeling_commit'] }}" class="font-mono text-primary underline underline-offset-4">{{ substr($lock['labeling_commit'], 0, 7) }}</a>
                and locked on {{ Carbon::parse($lock['locked_at'])->format('M j, Y') }} with a SHA-256 of every row
                (combined <code class="whitespace-nowrap">{{ substr($lock['combined_sha256'], 0, 12) }}</code>). The eval refuses to run if any label changes.
            </p>
        @endif

        @if (! $showNames)
            <div class="rule-heavy mt-8 max-w-[46rem] bg-band px-5 pb-5 lg:col-span-8">
                <h2 class="text-xl font-extrabold">Totals only on this page</h2>
                <p class="mt-2">
                    These are real businesses that have not joined Local Goods, so this public page shows totals only: no names, notes or per-business results.
                    Signed-in reviewers with their own link see every disagreement with the hand label's note and the AI's reasoning.
                </p>
            </div>
        @endif

        @if ($latest === null)
            <p class="rule-heavy mt-8">
                No results yet. Run <code>php artisan merchant:eval</code> to create them.
            </p>
        @else
            <p class="mt-6 max-w-[46rem] text-sm text-muted lg:col-span-8">
                Current prompt version <code>{{ $promptVersion }}</code>.
                Prompt versions that have seen the holdout:
                @if ($latest['holdout_seen_by'] === []) none yet. @else @foreach ($latest['holdout_seen_by'] as $v)<code>{{ $v }}</code>@if (! $loop->last), @endif @endforeach. @endif
            </p>
        @endif
        </section>

        @if ($latest !== null)

            @foreach (MerchantEvalRunner::SPLITS as $split => $title)
                @php $run = $latest['splits'][$split] ?? null; @endphp
                <section aria-labelledby="{{ $split }}-heading" class="stop">
                    @php $stop++; @endphp
                    <x-stop-number :n="$stop" />
                    <x-station-sign heading-id="{{ $split }}-heading">{{ $title }}</x-station-sign>

                    @if ($run === null)
                        <p class="mt-6">
                            Not run yet. @if ($split === 'holdout') The holdout is scored once, after the prompt is final. @endif
                        </p>
                    @else
                        @php $s = $run['summary']; @endphp
                        <p class="mt-4 text-sm text-muted">
                            {{ $s['cases'] }} cases · run {{ Carbon::parse($run['run_at'])->format('M j, Y') }} · model <code>{{ $run['model'] }}</code> ·
                            prompt <code>{{ $run['prompt_version'] }}</code>@if ($run['prompt_version'] !== $promptVersion) (not the current prompt)@endif
                        </p>

                        <dl class="mt-8 grid gap-x-6 gap-y-8 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="rule-top">
                                <dt class="text-sm font-bold">Eligible yes/no agreement</dt>
                                <dd class="money mt-2 text-4xl">{{ $rate($s['agreement']) }}</dd>
                                <dd class="mt-1 text-sm text-muted">{{ $pct($s['agreement']) }}, {{ $ci($s['agreement']) }}</dd>
                            </div>
                            <div class="rule-top">
                                <dt class="text-sm font-bold">Baseline: always answer “not eligible”</dt>
                                <dd class="money mt-2 text-4xl">{{ $rate($s['baseline_always_not_eligible']) }}</dd>
                                <dd class="mt-1 text-sm text-muted">{{ $pct($s['baseline_always_not_eligible']) }}. Most labeled businesses are not independent, so agreement alone flatters.</dd>
                            </div>
                            <div class="rule-top">
                                <dt class="text-sm font-bold">Independent businesses the AI found</dt>
                                <dd class="money mt-2 text-4xl">{{ $rate($s['recall']) }}</dd>
                                <dd class="mt-1 text-sm text-muted">Of Finn's independent ones, the AI called eligible. {{ $ci($s['recall']) }}</dd>
                            </div>
                            <div class="rule-top">
                                <dt class="text-sm font-bold">AI “eligible” calls that were right</dt>
                                <dd class="money mt-2 text-4xl">{{ $rate($s['precision']) }}</dd>
                                <dd class="mt-1 text-sm text-muted">Of the AI's eligible calls, Finn labeled independent. {{ $ci($s['precision']) }}</dd>
                            </div>
                        </dl>

                        @if (isset($s['triage']))
                            <div class="mt-12">
                                <h3 class="stop-subtitle">Triage</h3>
                                <dl class="mt-5 grid gap-x-6 gap-y-8 sm:grid-cols-3">
                                    <div class="rule-top">
                                        <dt class="text-sm font-bold">Chains and franchises correctly called not eligible</dt>
                                        <dd class="money mt-2 text-4xl">{{ $rate($s['triage']['chains_not_eligible']) }}</dd>
                                        <dd class="mt-1 text-sm text-muted">{{ $pct($s['triage']['chains_not_eligible']) }}, {{ $ci($s['triage']['chains_not_eligible']) }}</dd>
                                    </div>
                                    <div class="rule-top">
                                        <dt class="text-sm font-bold">Chains and franchises wrongly called eligible</dt>
                                        <dd class="money mt-2 text-4xl">{{ $rate($s['triage']['chains_called_eligible']) }}</dd>
                                        <dd class="mt-1 text-sm text-muted">{{ $pct($s['triage']['chains_called_eligible']) }}, {{ $ci($s['triage']['chains_called_eligible']) }}. The costliest mistake; a person still decides.</dd>
                                    </div>
                                    <div class="rule-top">
                                        <dt class="text-sm font-bold">All cases routed to human review</dt>
                                        <dd class="money mt-2 text-4xl">{{ $rate($s['triage']['routed_to_review']) }}</dd>
                                        <dd class="mt-1 text-sm text-muted">{{ $pct($s['triage']['routed_to_review']) }}, {{ $ci($s['triage']['routed_to_review']) }}</dd>
                                    </div>
                                </dl>
                            </div>
                        @endif

                        <ul class="mt-10 max-w-[52rem] border-t-[3px] border-ink text-sm [&>li]:border-b [&>li]:border-line [&>li]:py-2">
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

                        <div class="mt-10 grid gap-x-8 gap-y-10 lg:grid-cols-2">
                            <x-rtable :card="false" label="Confusion matrix ({{ $title }}) table">
                                <table data-rtable="stack" class="tabular w-full text-left">
                                    <caption class="pb-3 text-left font-bold">Confusion matrix ({{ $title }}): rows are Finn's labels, columns the AI's answers</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col" class="py-2 pr-4">Finn</th>
                                            @foreach (MerchantEvalReport::OUTCOME_NAMES as $name)<th scope="col" class="px-4 py-2">AI: {{ $name }}</th>@endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($s['matrix'] as $label => $row)
                                            <tr class="border-t border-line align-top">
                                                <th scope="row" data-primary class="py-2.5 pr-4 font-bold">{{ MerchantEvalReport::LABEL_NAMES[$label] }}</th>
                                                @foreach ($row as $outcome => $n)<td data-label="AI: {{ MerchantEvalReport::OUTCOME_NAMES[$outcome] ?? $outcome }}" class="ledger-num whitespace-nowrap px-4 py-2.5">{{ $n }}</td>@endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </x-rtable>
                            <x-rtable :card="false" label="Not independent, by reason ({{ $title }}) table">
                                <table data-rtable="stack" class="tabular w-full text-left">
                                    <caption class="pb-3 text-left font-bold">Not independent, by the reason Finn gave ({{ $title }})</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col" class="py-2 pr-4">Reason</th>
                                            <th scope="col" class="px-4 py-2">Cases</th>
                                            <th scope="col" class="px-4 py-2">AI: not eligible</th>
                                            <th scope="col" class="px-4 py-2">AI: same reason</th>
                                            <th scope="col" class="px-4 py-2">AI: undetermined</th>
                                            <th scope="col" class="px-4 py-2">AI: eligible</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($s['by_fail_reason'] as $reason => $r)
                                            <tr class="border-t border-line align-top">
                                                <th scope="row" data-primary class="py-2.5 pr-4 font-bold">{{ MerchantEvalReport::CLASS_NAMES[$reason] }}</th>
                                                <td data-label="Cases" class="ledger-num whitespace-nowrap px-4 py-2.5">{{ $r['cases'] }}</td>
                                                <td data-label="AI: not eligible" class="ledger-num whitespace-nowrap px-4 py-2.5">{{ $rate($r['not_eligible']) }}</td>
                                                <td data-label="AI: same reason" class="ledger-num whitespace-nowrap px-4 py-2.5">{{ $rate($r['same_reason']) }}</td>
                                                <td data-label="AI: undetermined" class="ledger-num whitespace-nowrap px-4 py-2.5">{{ $r['undetermined'] }}</td>
                                                <td data-label="AI: eligible" class="ledger-num whitespace-nowrap px-4 py-2.5">{{ $r['called_eligible'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </x-rtable>
                        </div>

                        @isset($s['category_disagreements'])
                            <div class="mt-10 max-w-[46rem]">
                                <h3 class="stop-subtitle">Category disagreements ({{ $s['category_disagreements'] }})</h3>
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
                            <div class="mt-12">
                                <h3 class="stop-subtitle">Disagreements ({{ count($disagreements) }})</h3>
                                <p class="mt-1 text-sm text-muted">Every case where the AI's classification differs from Finn's. “Eligible answer differs” means the yes/no answer differs too.</p>
                                <ul class="mt-4 border-t-[3px] border-ink">
                                    @foreach ($disagreements as $r)
                                        <li class="border-b border-line py-5">
                                            <div class="flex flex-wrap items-start justify-between gap-2">
                                                <div>
                                                    <p class="text-lg font-bold"><span class="ledger-num">{{ $r['candidate_id'] }}</span> · {{ $r['name'] }}</p>
                                                    <p class="text-sm text-muted">{{ ucfirst($r['category']) }}@if ($r['business_activity']) · license: {{ $r['business_activity'] }}@endif</p>
                                                    <p class="mt-1 text-xs font-semibold uppercase tracking-wider text-accent-ink">{{ $candidateLabel }}</p>
                                                </div>
                                                @unless ($r['agree'])<x-badge tone="hold">Eligible answer differs</x-badge>@endunless
                                            </div>
                                            <dl class="mt-3 grid gap-x-8 gap-y-4 md:grid-cols-2">
                                                <div>
                                                    <dt class="text-sm font-bold">Finn</dt>
                                                    <dd class="mt-1"><span class="font-semibold">{{ MerchantEvalReport::LABEL_NAMES[$r['my_label']] }}@if ($r['fail_reason']) ({{ MerchantEvalReport::CLASS_NAMES[$r['fail_reason']] }})@endif.</span> {{ $r['my_note'] }}
                                                        @if ($r['evidence_url'])<a href="{{ EvidenceUrl::withoutTracking($r['evidence_url']) }}" class="underline underline-offset-4" rel="noopener" target="_blank">Source<span class="sr-only"> for {{ $r['name'] }} (opens in a new tab)</span></a>@endif
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt class="text-sm font-bold">AI</dt>
                                                    <dd class="mt-1"><span class="font-semibold">{{ MerchantEvalReport::CLASS_NAMES[$r['ai_classification']] }}, confidence {{ round(100 * $r['confidence']) }}%.</span> {{ $r['reasoning'] }}@if (! empty($r['redacted']))<span class="mt-1 block text-sm text-muted">{{ $r['redacted'] }}</span>@endif</dd>
                                                </div>
                                            </dl>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endif
                </section>
            @endforeach

            <section aria-labelledby="history-heading" class="stop">
                @php $stop++; @endphp
                <x-stop-number :n="$stop" />
                <x-station-sign heading-id="history-heading">Run history</x-station-sign>
                <x-rtable :card="false" class="mt-6" label="Every merchant eval run table">
                    <table data-rtable="stack" class="tabular w-full min-w-2xl text-left">
                        <caption class="sr-only">Every merchant eval run, oldest first</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="py-2 pr-4">Run</th>
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
                                <tr class="border-t border-line align-top">
                                    <td data-primary class="ledger-num py-2.5 pr-4">{{ Carbon::parse($h['run_at'])->format('M j, Y g:i A') }}</td>
                                    <td data-label="Split" class="px-4 py-2">{{ MerchantEvalRunner::SPLITS[$h['split']] ?? $h['split'] }}</td>
                                    <td data-label="Prompt" class="px-4 py-2"><code>{{ $h['prompt_version'] }}</code></td>
                                    <td data-label="Agreement" class="ledger-num whitespace-nowrap px-4 py-2.5">{{ $h['agreement_pct'] }}% of {{ $h['cases'] }}</td>
                                    <td data-label="Independent found" class="ledger-num whitespace-nowrap px-4 py-2.5">{{ $rate($h['recall']) }}</td>
                                    <td data-label="Eligible calls right" class="ledger-num whitespace-nowrap px-4 py-2.5">{{ $rate($h['precision']) }}</td>
                                    <td data-label="Note" class="px-4 py-2 text-sm">{{ $h['note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-rtable>
            </section>
        @endif
        </div>
    </div>
@endsection
