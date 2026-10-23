{{-- The Redemption Fraud Detection eval results. Shown publicly at /eval and
     inside the app shell at /bid-admin/eval. Needs $run, $lock, $hideCaseDetails
     and the blind adjudication data (FraudEvalController::data). --}}
@php
    use App\Services\Fraud\FraudEvalPatterns;
    use App\Services\Fraud\FraudEvalReport;
    use Illuminate\Support\Carbon;
@endphp

    <header class="max-w-3xl">
        <x-eyebrow class="text-accent-ink">Redemption Fraud Detection</x-eyebrow>
        <h1 class="mt-1 text-4xl font-semibold text-primary sm:text-5xl">How often does the AI agree with a person?</h1>
        <p class="mt-3 text-lg text-muted">
            We wrote 90 synthetic redemptions and a person labeled each one <em>normal</em> or <em>suspicious</em>
            by hand, before the AI saw them. Then the real fraud check (the same prompt and model as production)
            scored every case. An AI <em>hold</em> counts as suspicious and a <em>clear</em> counts as normal.
            The <strong>holdout 3</strong> cases (50 of them) were labeled and locked after the last prompt change and before the AI saw them,
            so they are the cleanest test of the current prompt. Earlier groups are smaller and are kept below.
            <strong>Catch rate</strong> is how many of the suspicious cases the AI held; <strong>hold precision</strong> is how many of the AI's holds
            the person had labeled suspicious.
        </p>
    </header>

    @if ($run === null)
        <p class="rounded-2xl bg-surface p-5 ring-1 ring-line/70">
            No eval results yet. Run <code>php artisan fraud:eval</code> to create them.
        </p>
    @else
        @php
            $s = $run['summary'];
            $disagreements = array_values(array_filter($run['results'], fn ($r) => ! $r['agree']));
        @endphp

        @php $headline = $run['headline'] ?? null; @endphp
        @if ($headline)
            <section aria-labelledby="headline-heading" class="relative overflow-hidden rounded-3xl bg-night p-6 text-on-night sm:p-8">
                <h2 id="headline-heading" class="font-sans text-sm font-semibold uppercase tracking-widest text-night-accent">Headline result</h2>
                <p class="mt-2 max-w-3xl font-display text-2xl font-semibold sm:text-4xl">{{ $headline['text'] }}</p>
                <p class="mt-4 max-w-3xl">{{ $headline['sample'] }}</p>
                @if ($headline['note'])
                    <p class="mt-4 max-w-3xl">{{ $headline['note'] }}</p>
                @endif
                <p class="mt-4 max-w-3xl text-sm text-night-muted">
                    From the run on {{ Carbon::parse($headline['run_at'])->format('M j, Y') }}
                    (prompt version <code>{{ $headline['prompt_version'] }}</code>, <code>{{ $headline['split'] }}</code> cases).
                </p>
                @if ($lock && $lock['split'] === $headline['split'])
                    <p class="mt-2 max-w-3xl text-sm text-night-muted">
                        <strong class="text-on-night">Locked before the AI saw them:</strong>
                        the {{ $lock['case_count'] }} cases were labeled by hand in commit
                        <a href="{{ config('fraud.eval_repo_url') }}/commit/{{ $lock['labeling_commit'] }}" class="font-mono text-on-night underline underline-offset-4">{{ substr($lock['labeling_commit'], 0, 7) }}</a>
                        and locked on {{ Carbon::parse($lock['locked_at'])->format('M j, Y') }} with a SHA-256 of every row
                        (combined <code class="break-all">{{ substr($lock['combined_sha256'], 0, 12) }}</code>,
                        <a href="{{ config('fraud.eval_repo_url') }}/blob/main/data/fraud_eval_lock.json" class="text-on-night underline underline-offset-4">lock file</a>).
                        The eval refuses to run if any of them changes.
                    </p>
                @endif
            </section>

            @php $patterns = $headline['summary']['patterns']; @endphp
            <section aria-labelledby="patterns-heading">
                <h2 id="patterns-heading" class="text-2xl font-semibold text-primary">Misses and false alarms by pattern</h2>
                <p class="mt-1 max-w-3xl text-sm text-muted">
                    On the {{ $headline['summary']['cases'] }} <code>{{ $headline['split'] }}</code> cases. Tags come from each case's facts only, never from the
                    person's label or the AI's answer, and a case can have several. <em>Missed</em>: labeled suspicious, AI cleared.
                    <em>False alarm</em>: labeled normal, AI held. The "why" comes from the AI's own explanations.
                </p>
                @foreach (['tags' => ['Pattern', FraudEvalPatterns::TAGS], 'categories' => ['Merchant category', []]] as $key => [$heading, $names])
                    <div class="mt-3 scroll-region" role="region" tabindex="0" aria-label="Misses and false alarms by {{ strtolower($heading) }} table">
                        <table class="tabular w-full min-w-2xl rounded-2xl bg-surface text-left ring-1 ring-line/70">
                            <caption class="sr-only">Misses and false alarms by {{ strtolower($heading) }}</caption>
                            <thead>
                                <tr class="bg-band/60 text-sm text-muted">
                                    <th scope="col" class="px-4 py-2">{{ $heading }}</th>
                                    <th scope="col" class="px-4 py-2">Cases</th>
                                    <th scope="col" class="px-4 py-2">Labeled suspicious</th>
                                    <th scope="col" class="px-4 py-2">Missed</th>
                                    <th scope="col" class="px-4 py-2">False alarms</th>
                                    <th scope="col" class="px-4 py-2">Why</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($patterns[$key] as $name => $r)
                                    <tr class="border-t border-line/70 align-top">
                                        <th scope="row" class="px-4 py-2 font-semibold">{{ $names[$name] ?? ucfirst($name) }}</th>
                                        <td class="px-4 py-2">{{ $r['cases'] }}</td>
                                        <td class="px-4 py-2">{{ $r['suspicious'] }}</td>
                                        <td class="px-4 py-2">{{ $r['missed'] }}</td>
                                        <td class="px-4 py-2">{{ $r['false_alarms'] }}</td>
                                        {{-- The notes name cases and the AI's calls, so they wait until a blind adjudicator has voted. --}}
                                        <td class="px-4 py-2 text-sm">{{ $hideCaseDetails ? 'Shown after you submit your votes.' : ($headline['pattern_notes'][$name] ?? '') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </section>
        @endif

        <section aria-labelledby="latest-heading">
            <h2 id="latest-heading" class="text-2xl font-semibold text-primary">Latest run</h2>
            @if (! empty($run['note']))
                <p class="mt-3 rounded-2xl bg-hold-bg p-4 text-hold"><strong>Note on this run:</strong> {{ $run['note'] }}</p>
            @endif
            <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (array_filter(['holdout_3' => 'Holdout 3 agreement', 'holdout_2' => 'Holdout 2 agreement', 'holdout' => 'Holdout agreement', 'dev' => 'Dev agreement', 'all' => 'All cases'], fn ($label, $key) => ($s[$key]['cases'] ?? 0) > 0, ARRAY_FILTER_USE_BOTH) as $key => $label)
                    @php $dark = $key === 'holdout' && ! $headline; @endphp
                    <div @class(['rounded-2xl p-5', 'bg-night text-on-night' => $dark, 'bg-surface ring-1 ring-line/70' => ! $dark])>
                        <dt @class(['text-sm font-semibold', 'text-muted' => ! $dark, 'text-night-muted' => $dark])>{{ $label }}</dt>
                        <dd @class(['tabular mt-1 font-display text-4xl font-semibold', 'text-primary' => ! $dark])>{{ $s[$key]['agreement_pct'] }}%</dd>
                        <dd @class(['text-sm', 'text-muted' => ! $dark, 'text-night-muted' => $dark])>{{ $s[$key]['agree'] }} of {{ $s[$key]['cases'] }} cases ({{ $s[$key]['catch']['of'] }} labeled suspicious)</dd>
                        <dd @class(['mt-2 text-sm', 'text-muted' => ! $dark, 'text-night-muted' => $dark])>Caught {{ FraudEvalReport::rate($s[$key]['catch']) }}</dd>
                        <dd @class(['text-sm', 'text-muted' => ! $dark, 'text-night-muted' => $dark])>Holds suspicious: {{ FraudEvalReport::rate($s[$key]['hold_precision']) }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-3 text-sm text-muted">
                Run {{ Carbon::parse($run['run_at'])->format('M j, Y g:i A') }} ·
                model <code>{{ $run['model'] }}</code> ·
                prompt version <code>{{ $run['prompt_version'] }}</code>
            </p>
        </section>

        <section aria-labelledby="matrix-heading">
            <h2 id="matrix-heading" class="text-2xl font-semibold text-primary">Where they agreed and disagreed</h2>
            <div class="mt-3 grid gap-4 md:grid-cols-2">
                @foreach (array_filter(['holdout_3' => 'Holdout 3 cases', 'holdout_2' => 'Holdout 2 cases', 'holdout' => 'Holdout cases', 'dev' => 'Dev cases'], fn ($title, $key) => ($s[$key]['cases'] ?? 0) > 0, ARRAY_FILTER_USE_BOTH) as $key => $title)
                    @php $m = $s[$key]['matrix']; @endphp
                    <table class="tabular w-full overflow-hidden rounded-2xl bg-surface text-left ring-1 ring-line/70">
                        <caption class="px-4 pt-4 pb-1 text-left font-semibold">{{ $title }}: person's label (rows) vs the AI's call (columns)</caption>
                        <thead>
                            <tr class="bg-band/60 text-sm text-muted">
                                <td class="px-4 py-2"></td>
                                <th scope="col" class="px-4 py-2 font-semibold">AI: clear</th>
                                <th scope="col" class="px-4 py-2 font-semibold">AI: hold</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-t border-line/70">
                                <th scope="row" class="px-4 py-2 font-semibold">Person: normal</th>
                                <td class="px-4 py-2">{{ $m['normal']['normal'] }} <span class="text-sm text-muted">agree</span></td>
                                <td class="px-4 py-2">{{ $m['normal']['suspicious'] }} <span class="text-sm text-muted">false alarm</span></td>
                            </tr>
                            <tr class="border-t border-line/70">
                                <th scope="row" class="px-4 py-2 font-semibold">Person: suspicious</th>
                                <td class="px-4 py-2">{{ $m['suspicious']['normal'] }} <span class="text-sm text-muted">missed</span></td>
                                <td class="px-4 py-2">{{ $m['suspicious']['suspicious'] }} <span class="text-sm text-muted">agree</span></td>
                            </tr>
                        </tbody>
                    </table>
                @endforeach
            </div>
        </section>

        @if ($adjudication)
            @include('partials.adjudication-results')
        @elseif (! $hideCaseDetails)
            {{-- Where the judge home page's "Independent check of the AI" links to, before anyone has submitted. --}}
            <section id="adjudication" aria-labelledby="adjudication-heading" class="max-w-3xl scroll-mt-24">
                <h2 id="adjudication-heading" class="text-2xl font-semibold text-primary">Blind adjudication of the disagreements</h2>
                <p class="mt-1 text-muted">
                    Where the person's label and the AI disagree, invited BID staff and contest judges rate each case blind, with agreed cases mixed in as hidden controls.
                    @if ($adjudicationSet?->isOpen())
                        Voting is open until {{ \App\Models\AdjudicationSet::votingEndsAt()->format('M j') }}. Results appear here once the first adjudicator submits.
                    @elseif ($adjudicationSet?->isClosed())
                        Voting closed before anyone submitted.
                    @else
                        Voting hasn't started yet.
                    @endif
                </p>
            </section>
        @endif

        <section aria-labelledby="disagree-heading">
            <h2 id="disagree-heading" class="text-2xl font-semibold text-primary">Every disagreement ({{ count($disagreements) }})</h2>
            @if ($hideCaseDetails)
                <p class="mt-3 rounded-2xl border-2 border-line bg-band p-4">
                    Hidden for now: you're invited to
                    <a href="{{ route('adjudication.show') }}" class="font-semibold text-primary underline underline-offset-4">help check the AI</a>,
                    and this list would show you the answers. It appears here once you say you're done (or have seen every case).
                </p>
            @elseif ($disagreements === [])
                <p class="mt-3">The AI agreed with the person on every case.</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach ($disagreements as $r)
                        <li class="rounded-2xl border-l-4 border-l-accent bg-surface p-5 ring-1 ring-line/70 sm:p-6">
                            <p class="font-semibold">
                                {{ $r['case_id'] }} · ${{ $r['amount'] }} at {{ $r['merchant'] }}, {{ $r['local_time'] }}
                                <x-badge class="ml-2">{{ $r['split'] }}</x-badge>
                            </p>
                            <div class="mt-4 grid gap-4 border-t border-line/70 pt-4 md:grid-cols-2">
                                <div>
                                    <h3 class="font-sans text-xs font-semibold uppercase tracking-widest text-muted">Person: {{ $r['my_label'] }}</h3>
                                    <p class="mt-1">{{ $r['notes'] }}</p>
                                </div>
                                <div>
                                    <h3 class="font-sans text-xs font-semibold uppercase tracking-widest text-muted">AI: {{ $r['ai_decision'] }}, risk {{ $r['risk_score'] }}/100</h3>
                                    <p class="mt-1">{{ $r['explanation'] }}</p>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if (count($run['history'] ?? []) > 1)
            <section aria-labelledby="history-heading">
                <h2 id="history-heading" class="text-2xl font-semibold text-primary">Earlier runs</h2>
                <p class="mt-1 text-sm text-muted">Every run is kept. A new prompt version means the prompt was changed between runs. Each cell: agreement, and how many of the suspicious cases the AI caught.</p>
                <div class="mt-3 scroll-region" role="region" tabindex="0" aria-label="Agreement for every eval run table">
                    <table class="tabular w-full min-w-3xl rounded-2xl bg-surface text-left ring-1 ring-line/70">
                        <caption class="sr-only">Agreement for every eval run</caption>
                        <thead>
                            <tr class="bg-band/60 text-sm text-muted">
                                <th scope="col" class="px-4 py-2">Run</th>
                                <th scope="col" class="px-4 py-2">Prompt version</th>
                                <th scope="col" class="px-4 py-2">Dev</th>
                                <th scope="col" class="px-4 py-2">Holdout</th>
                                <th scope="col" class="px-4 py-2">Holdout 2</th>
                                <th scope="col" class="px-4 py-2">Holdout 3</th>
                                <th scope="col" class="px-4 py-2">Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($run['history'] as $h)
                                <tr class="border-t border-line/70">
                                    <td class="px-4 py-2">{{ Carbon::parse($h['run_at'])->format('M j, Y g:i A') }}</td>
                                    <td class="px-4 py-2"><code>{{ $h['prompt_version'] }}</code></td>
                                    @foreach (['dev', 'holdout', 'holdout_2', 'holdout_3'] as $split)
                                        <td class="px-4 py-2 text-sm">{{ FraudEvalReport::historyCell($h['summary'][$split]) }}</td>
                                    @endforeach
                                    <td class="px-4 py-2 text-sm">{{ $h['note'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif
