{{-- The Redemption Fraud Detection eval results. Shown publicly at /eval and
     inside the app shell at /bid-admin/eval. Needs $run. --}}
@php use Illuminate\Support\Carbon; @endphp

    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Redemption Fraud Detection</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">How often does the AI agree with a person?</h1>
        <p class="mt-3 text-lg text-muted">
            We wrote 30 synthetic redemptions and a person labeled each one <em>normal</em> or <em>suspicious</em>
            by hand, before the AI saw them. Then the real fraud check (the same prompt and model as production)
            scored every case. An AI <em>hold</em> counts as suspicious and a <em>clear</em> counts as normal.
            The <strong>holdout</strong> cases were never used to change the prompt. The <strong>holdout 2</strong> cases were
            labeled after the last prompt change and never seen while changing it, so they are the cleanest test of the current prompt.
        </p>
    </header>

    @if ($run === null)
        <p class="rounded-2xl bg-paper p-5 ring-1 ring-line/70">
            No eval results yet. Run <code>php artisan fraud:eval</code> to create them.
        </p>
    @else
        @php
            $s = $run['summary'];
            $disagreements = array_values(array_filter($run['results'], fn ($r) => ! $r['agree']));
        @endphp

        @php $headline = $run['headline'] ?? null; @endphp
        @if ($headline)
            <section aria-labelledby="headline-heading" class="relative overflow-hidden rounded-3xl bg-forest p-6 text-cream sm:p-8">
                <div class="awning absolute inset-x-0 top-0 h-1.5 opacity-60" aria-hidden="true"></div>
                <h2 id="headline-heading" class="font-sans text-sm font-semibold uppercase tracking-widest text-amber">Headline result</h2>
                <p class="mt-2 max-w-3xl font-display text-2xl font-semibold sm:text-4xl">{{ $headline['text'] }}</p>
                @if ($headline['note'])
                    <p class="mt-4 max-w-3xl">{{ $headline['note'] }}</p>
                @endif
                <p class="mt-4 max-w-3xl text-sm text-sage">
                    {{ $headline['sample'] }}
                    From the run on {{ Carbon::parse($headline['run_at'])->format('M j, Y') }}
                    (prompt version <code>{{ $headline['prompt_version'] }}</code>, <code>{{ $headline['split'] }}</code> cases).
                </p>
            </section>
        @endif

        <section aria-labelledby="latest-heading">
            <h2 id="latest-heading" class="text-2xl font-semibold text-forest">Latest run</h2>
            @if (! empty($run['note']))
                <p class="mt-3 rounded-2xl bg-hold-bg p-4 text-hold"><strong>Note on this run:</strong> {{ $run['note'] }}</p>
            @endif
            <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (array_filter(['holdout_2' => 'Holdout 2 agreement', 'holdout' => 'Holdout agreement', 'dev' => 'Dev agreement', 'all' => 'All cases'], fn ($label, $key) => ($s[$key]['cases'] ?? 0) > 0, ARRAY_FILTER_USE_BOTH) as $key => $label)
                    @php $dark = $key === 'holdout' && ! $headline; @endphp
                    <div @class(['rounded-2xl p-5', 'bg-forest text-cream' => $dark, 'bg-paper ring-1 ring-line/70' => ! $dark])>
                        <dt @class(['text-sm font-semibold', 'text-muted' => ! $dark, 'text-sage' => $dark])>{{ $label }}</dt>
                        <dd @class(['tabular mt-1 font-display text-4xl font-semibold', 'text-forest' => ! $dark])>{{ $s[$key]['agreement_pct'] }}%</dd>
                        <dd @class(['text-sm', 'text-muted' => ! $dark, 'text-sage' => $dark])>{{ $s[$key]['agree'] }} of {{ $s[$key]['cases'] }} cases</dd>
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
            <h2 id="matrix-heading" class="text-2xl font-semibold text-forest">Where they agreed and disagreed</h2>
            <div class="mt-3 grid gap-4 md:grid-cols-2">
                @foreach (array_filter(['dev' => 'Dev cases', 'holdout' => 'Holdout cases', 'holdout_2' => 'Holdout 2 cases'], fn ($title, $key) => ($s[$key]['cases'] ?? 0) > 0, ARRAY_FILTER_USE_BOTH) as $key => $title)
                    @php $m = $s[$key]['matrix']; @endphp
                    <table class="tabular w-full overflow-hidden rounded-2xl bg-paper text-left ring-1 ring-line/70">
                        <caption class="px-4 pt-4 pb-1 text-left font-semibold">{{ $title }}: person's label (rows) vs the AI's call (columns)</caption>
                        <thead>
                            <tr class="bg-cream-deep/60 text-sm text-muted">
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

        <section aria-labelledby="disagree-heading">
            <h2 id="disagree-heading" class="text-2xl font-semibold text-forest">Every disagreement ({{ count($disagreements) }})</h2>
            @if ($disagreements === [])
                <p class="mt-3">The AI agreed with the person on every case.</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach ($disagreements as $r)
                        <li class="rounded-2xl border-l-4 border-l-amber bg-paper p-5 ring-1 ring-line/70 sm:p-6">
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
                <h2 id="history-heading" class="text-2xl font-semibold text-forest">Earlier runs</h2>
                <p class="mt-1 text-sm text-muted">Every run is kept. A new prompt version means the prompt was changed between runs.</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="tabular w-full min-w-xl rounded-2xl bg-paper text-left ring-1 ring-line/70">
                        <caption class="sr-only">Agreement for every eval run</caption>
                        <thead>
                            <tr class="bg-cream-deep/60 text-sm text-muted">
                                <th scope="col" class="px-4 py-2">Run</th>
                                <th scope="col" class="px-4 py-2">Prompt version</th>
                                <th scope="col" class="px-4 py-2">Dev</th>
                                <th scope="col" class="px-4 py-2">Holdout</th>
                                <th scope="col" class="px-4 py-2">Holdout 2</th>
                                <th scope="col" class="px-4 py-2">Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($run['history'] as $h)
                                <tr class="border-t border-line/70">
                                    <td class="px-4 py-2">{{ Carbon::parse($h['run_at'])->format('M j, Y g:i A') }}</td>
                                    <td class="px-4 py-2"><code>{{ $h['prompt_version'] }}</code></td>
                                    <td class="px-4 py-2">{{ $h['dev_pct'] }}%</td>
                                    <td class="px-4 py-2">{{ $h['holdout_pct'] }}%</td>
                                    <td class="px-4 py-2">{{ ($h['holdout_2_pct'] ?? null) === null ? 'n/a' : $h['holdout_2_pct'].'%' }}</td>
                                    <td class="px-4 py-2 text-sm">{{ $h['note'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif
