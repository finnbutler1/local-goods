{{-- The Redemption Fraud Detection eval results. Shown publicly at /eval and
     inside the app shell at /bid-admin/eval. Needs $run and $lock
     (FraudEvalController::data). --}}
@php
    use App\Services\Fraud\FraudEvalPatterns;
    use App\Services\Fraud\FraudEvalReport;
    use Illuminate\Support\Carbon;
@endphp

    <header>
        <x-station-sign as="p">Redemption Fraud Detection</x-station-sign>
        <h1 class="page-title">{{ $heading ?? 'How often does the AI agree with a person?' }}</h1>
        @isset($subheading)<p class="mt-3 text-2xl font-bold tracking-tight text-ink">{{ $subheading }}</p>@endisset
        <p class="page-lede max-w-[46rem]">
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
        <p class="rule-heavy mt-10">
            No eval results yet. Run <code>php artisan fraud:eval</code> to create them.
        </p>
    @else
        @php
            $s = $run['summary'];
            $disagreements = array_values(array_filter($run['results'], fn ($r) => ! $r['agree']));
            $stop = 0;
        @endphp

        <div class="stop-line stop-line-dense">

        @php $headline = $run['headline'] ?? null; @endphp
        @if ($headline)
            <section aria-labelledby="headline-heading" class="stop">
                @php $stop++; @endphp
                <x-stop-number :n="$stop" />
                <x-station-sign heading-id="headline-heading">Headline result</x-station-sign>
                <p class="mt-6 max-w-[24ch] font-display text-3xl leading-[1.08] font-extrabold tracking-[-0.03em] text-primary sm:text-5xl lg:col-span-8">{{ $headline['text'] }}</p>
                <p class="mt-5 max-w-[42rem] text-lg lg:col-span-8">{{ $headline['sample'] }}</p>
                @if ($headline['note'])
                    <p class="mt-4 max-w-[42rem] lg:col-span-8">{{ $headline['note'] }}</p>
                @endif
                <div class="rule-top mt-6 max-w-[48rem] text-sm text-muted lg:col-span-8">
                <p>
                    From the run on {{ Carbon::parse($headline['run_at'])->format('M j, Y') }}
                    (prompt version <code>{{ $headline['prompt_version'] }}</code>, <code>{{ $headline['split'] }}</code> cases).
                </p>
                @if ($lock && $lock['split'] === $headline['split'])
                    <p class="mt-2">
                        <strong class="text-ink">Locked before the AI saw them:</strong>
                        the {{ $lock['case_count'] }} cases were labeled by hand in commit
                        <a href="{{ config('fraud.eval_repo_url') }}/commit/{{ $lock['labeling_commit'] }}" class="font-mono text-primary underline underline-offset-4">{{ substr($lock['labeling_commit'], 0, 7) }}</a>
                        and locked on {{ Carbon::parse($lock['locked_at'])->format('M j, Y') }} with a SHA-256 of every row
                        (combined <code class="whitespace-nowrap">{{ substr($lock['combined_sha256'], 0, 12) }}</code>,
                        <a href="{{ config('fraud.eval_repo_url') }}/blob/main/data/fraud_eval_lock.json" class="text-primary underline underline-offset-4">lock file</a>).
                        The eval refuses to run if any of them changes.
                    </p>
                @endif
                </div>
            </section>

            @php $patterns = $headline['summary']['patterns']; @endphp
            <section aria-labelledby="patterns-heading" class="stop">
                @php $stop++; @endphp
                <x-stop-number :n="$stop" />
                <x-station-sign heading-id="patterns-heading">Misses and false alarms by pattern</x-station-sign>
                <p class="mt-4 max-w-[52rem] text-sm text-muted">
                    On the {{ $headline['summary']['cases'] }} <code>{{ $headline['split'] }}</code> cases. Tags come from each case's facts only, never from the
                    person's label or the AI's answer, and a case can have several. <em>Missed</em>: labeled suspicious, AI cleared.
                    <em>False alarm</em>: labeled normal, AI held. The "why" comes from the AI's own explanations.
                </p>
                @foreach (['tags' => ['Pattern', FraudEvalPatterns::TAGS], 'categories' => ['Merchant category', []]] as $key => [$heading, $names])
                    <x-rtable :card="false" class="mt-6" label="Misses and false alarms by {{ strtolower($heading) }} table">
                        <table data-rtable="stack" class="tabular w-full min-w-2xl text-left">
                            <caption class="sr-only">Misses and false alarms by {{ strtolower($heading) }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="py-2 pr-4">{{ $heading }}</th>
                                    <th scope="col" class="px-4 py-2 text-right">Cases</th>
                                    <th scope="col" class="px-4 py-2 text-right">Labeled suspicious</th>
                                    <th scope="col" class="px-4 py-2 text-right">Missed</th>
                                    <th scope="col" class="px-4 py-2 text-right">False alarms</th>
                                    <th scope="col" class="py-2 pl-4">Why</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($patterns[$key] as $name => $r)
                                    <tr class="border-t border-line align-top">
                                        <th scope="row" data-primary class="py-2.5 pr-4 font-bold">{{ $names[$name] ?? ucfirst($name) }}</th>
                                        <td data-label="Cases" class="ledger-num px-4 py-2.5 text-right">{{ $r['cases'] }}</td>
                                        <td data-label="Labeled suspicious" class="ledger-num px-4 py-2.5 text-right">{{ $r['suspicious'] }}</td>
                                        <td data-label="Missed" class="ledger-num px-4 py-2.5 text-right">{{ $r['missed'] }}</td>
                                        <td data-label="False alarms" class="ledger-num px-4 py-2.5 text-right">{{ $r['false_alarms'] }}</td>
                                        <td data-label="Why" class="py-2.5 pl-4 text-sm">{{ $headline['pattern_notes'][$name] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-rtable>
                @endforeach
            </section>
        @endif

        <section aria-labelledby="latest-heading" class="stop">
            @php $stop++; @endphp
            <x-stop-number :n="$stop" />
            <x-station-sign heading-id="latest-heading">Latest run</x-station-sign>
            @if (! empty($run['note']))
                <p class="mt-5 border-t-[3px] border-hold bg-hold-bg px-4 py-3 text-hold"><strong>Note on this run:</strong> {{ $run['note'] }}</p>
            @endif
            <dl class="mt-8 grid gap-x-6 gap-y-8 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (array_filter(['holdout_3' => 'Holdout 3 agreement', 'holdout_2' => 'Holdout 2 agreement', 'holdout' => 'Holdout agreement', 'dev' => 'Dev agreement', 'all' => 'All cases'], fn ($label, $key) => ($s[$key]['cases'] ?? 0) > 0, ARRAY_FILTER_USE_BOTH) as $key => $label)
                    @php $dark = $key === 'holdout' && ! $headline; @endphp
                    <div @class(['rule-top', 'rule-heavy' => $dark])>
                        <dt class="text-sm font-bold">{{ $label }}</dt>
                        <dd @class(['money mt-2 text-4xl sm:text-5xl', 'text-primary' => $dark])>{{ $s[$key]['agreement_pct'] }}%</dd>
                        <dd class="mt-1 text-sm text-muted">{{ $s[$key]['agree'] }} of {{ $s[$key]['cases'] }} cases ({{ $s[$key]['catch']['of'] }} labeled suspicious)</dd>
                        <dd class="mt-2 border-t border-line pt-2 text-sm">Caught {{ FraudEvalReport::rate($s[$key]['catch']) }}</dd>
                        <dd class="text-sm">Holds suspicious: {{ FraudEvalReport::rate($s[$key]['hold_precision']) }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-6 text-sm text-muted">
                Run {{ Carbon::parse($run['run_at'])->format('M j, Y g:i A') }} ·
                model <code>{{ $run['model'] }}</code> ·
                prompt version <code>{{ $run['prompt_version'] }}</code>
            </p>
        </section>

        <section aria-labelledby="matrix-heading" class="stop">
            @php $stop++; @endphp
            <x-stop-number :n="$stop" />
            <x-station-sign heading-id="matrix-heading">Where they agreed and disagreed</x-station-sign>
            <div class="mt-6 grid gap-x-8 gap-y-8 md:grid-cols-2">
                @foreach (array_filter(['holdout_3' => 'Holdout 3 cases', 'holdout_2' => 'Holdout 2 cases', 'holdout' => 'Holdout cases', 'dev' => 'Dev cases'], fn ($title, $key) => ($s[$key]['cases'] ?? 0) > 0, ARRAY_FILTER_USE_BOTH) as $key => $title)
                    @php $m = $s[$key]['matrix']; @endphp
                    <table data-rtable="fit" class="tabular w-full text-left">
                        <caption class="pb-3 text-left font-bold">{{ $title }}: person's label (rows) vs the AI's call (columns)</caption>
                        <thead>
                            <tr>
                                <td class="border-b-[3px] border-frame py-2 pr-4"></td>
                                <th scope="col" class="px-4 py-2">AI: clear</th>
                                <th scope="col" class="px-4 py-2">AI: hold</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-t border-line">
                                <th scope="row" class="py-2.5 pr-4 font-bold">Person: normal</th>
                                <td class="px-4 py-2.5"><span class="ledger-num text-lg font-bold">{{ $m['normal']['normal'] }}</span> <span class="text-sm text-muted">agree</span></td>
                                <td class="px-4 py-2.5"><span class="ledger-num text-lg font-bold">{{ $m['normal']['suspicious'] }}</span> <span class="text-sm text-muted">false alarm</span></td>
                            </tr>
                            <tr class="border-t border-line">
                                <th scope="row" class="py-2.5 pr-4 font-bold">Person: suspicious</th>
                                <td class="px-4 py-2.5"><span class="ledger-num text-lg font-bold">{{ $m['suspicious']['normal'] }}</span> <span class="text-sm text-muted">missed</span></td>
                                <td class="px-4 py-2.5"><span class="ledger-num text-lg font-bold">{{ $m['suspicious']['suspicious'] }}</span> <span class="text-sm text-muted">agree</span></td>
                            </tr>
                        </tbody>
                    </table>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="disagree-heading" class="stop">
            @php $stop++; @endphp
            <x-stop-number :n="$stop" />
            <x-station-sign heading-id="disagree-heading">Every disagreement ({{ count($disagreements) }})</x-station-sign>
            @if ($disagreements === [])
                <p class="mt-5">The AI agreed with the person on every case.</p>
            @else
                <ul class="mt-6 border-t-[3px] border-ink">
                    @foreach ($disagreements as $r)
                        <li class="border-b border-line py-5">
                            <p class="font-bold">
                                <span class="ledger-num">{{ $r['case_id'] }}</span> · <span class="ledger-num">${{ $r['amount'] }}</span> at {{ $r['merchant'] }}, {{ $r['local_time'] }}
                                <x-badge class="ml-2">{{ $r['split'] }}</x-badge>
                            </p>
                            <div class="mt-3 grid gap-x-8 gap-y-4 md:grid-cols-2">
                                <div>
                                    <h3 class="text-sm font-bold">Person: {{ $r['my_label'] }}</h3>
                                    <p class="mt-1">{{ $r['notes'] }}</p>
                                </div>
                                <div>
                                    <h3 class="text-sm font-bold">AI: {{ $r['ai_decision'] }}, risk {{ $r['risk_score'] }}/100</h3>
                                    <p class="mt-1">{{ $r['explanation'] }}</p>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if (count($run['history'] ?? []) > 1)
            <section aria-labelledby="history-heading" class="stop">
                @php $stop++; @endphp
                <x-stop-number :n="$stop" />
                <x-station-sign heading-id="history-heading">Earlier runs</x-station-sign>
                <p class="mt-4 max-w-[52rem] text-sm text-muted">Every run is kept. A new prompt version means the prompt was changed between runs. Each cell: agreement, and how many of the suspicious cases the AI caught.</p>
                <x-rtable :card="false" class="mt-6" label="Agreement for every eval run table">
                    <table data-rtable="stack" class="tabular w-full min-w-3xl text-left">
                        <caption class="sr-only">Agreement for every eval run</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="py-2 pr-4">Run</th>
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
                                <tr class="border-t border-line align-top">
                                    <td data-primary class="ledger-num py-2.5 pr-4">{{ Carbon::parse($h['run_at'])->format('M j, Y g:i A') }}</td>
                                    <td data-label="Prompt version" class="px-4 py-2"><code>{{ $h['prompt_version'] }}</code></td>
                                    @foreach (['dev', 'holdout', 'holdout_2', 'holdout_3'] as $split)
                                        <td data-label="{{ \App\Services\Fraud\FraudEvalRunner::SPLITS[$split] }}" class="px-4 py-2.5 text-sm">{{ FraudEvalReport::historyCell($h['summary'][$split]) }}</td>
                                    @endforeach
                                    <td data-label="Note" class="px-4 py-2 text-sm">{{ $h['note'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-rtable>
            </section>
        @endif
        </div>
    @endif
