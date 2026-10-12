@php use Illuminate\Support\Carbon; @endphp
@extends('layouts.app')

@section('title', 'Fraud check eval · Local Goods')

@section('content')
<div class="mx-auto max-w-5xl px-4 py-8 space-y-8">
    <nav aria-label="Demo pages" class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm font-semibold">
        <a href="{{ route('demo.bid-admin') }}" class="underline underline-offset-4 hover:no-underline">
            <span aria-hidden="true">←</span> Back to the BID admin view
        </a>
    </nav>

    <header>
        <p class="text-sm font-semibold uppercase tracking-widest text-muted">Redemption Fraud Detection</p>
        <h1 class="mt-1 text-3xl font-semibold tracking-tight">How often does the AI agree with a person?</h1>
        <p class="mt-2 max-w-2xl text-muted">
            We wrote 30 synthetic redemptions and a person labeled each one <em>normal</em> or <em>suspicious</em>
            by hand, before the AI saw them. Then the real fraud check (the same prompt and model as production)
            scored every case. An AI <em>hold</em> counts as suspicious and a <em>clear</em> counts as normal.
            The <strong>holdout</strong> cases were never used to change the prompt, so they are the fairest test.
        </p>
    </header>

    @if ($run === null)
        <p class="rounded-xl bg-white p-5 ring-1 ring-cream-deep">
            No eval results yet. Run <code>php artisan fraud:eval</code> to create them.
        </p>
    @else
        @php
            $s = $run['summary'];
            $disagreements = array_values(array_filter($run['results'], fn ($r) => ! $r['agree']));
        @endphp

        <section aria-labelledby="headline-heading">
            <h2 id="headline-heading" class="sr-only">Headline numbers</h2>
            <dl class="grid gap-3 sm:grid-cols-3">
                @foreach (['holdout' => 'Holdout agreement', 'dev' => 'Dev agreement', 'all' => 'All cases'] as $key => $label)
                    <div @class(['rounded-xl p-4', 'bg-forest text-cream' => $key === 'holdout', 'bg-white ring-1 ring-cream-deep' => $key !== 'holdout'])>
                        <dt @class(['text-sm', 'text-muted' => $key !== 'holdout'])>{{ $label }}</dt>
                        <dd class="text-3xl font-semibold">{{ $s[$key]['agreement_pct'] }}%</dd>
                        <dd @class(['text-sm', 'text-muted' => $key !== 'holdout'])>{{ $s[$key]['agree'] }} of {{ $s[$key]['cases'] }} cases</dd>
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
            <h2 id="matrix-heading" class="text-xl font-semibold">Where they agreed and disagreed</h2>
            <div class="mt-3 grid gap-4 md:grid-cols-2">
                @foreach (['dev' => 'Dev cases', 'holdout' => 'Holdout cases'] as $key => $title)
                    @php $m = $s[$key]['matrix']; @endphp
                    <table class="w-full rounded-xl bg-white text-left ring-1 ring-cream-deep">
                        <caption class="px-4 pt-3 text-left font-semibold">{{ $title }}: person's label (rows) vs the AI's call (columns)</caption>
                        <thead>
                            <tr class="text-sm text-muted">
                                <td class="px-4 py-2"></td>
                                <th scope="col" class="px-4 py-2 font-semibold">AI: clear</th>
                                <th scope="col" class="px-4 py-2 font-semibold">AI: hold</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-t border-cream-deep">
                                <th scope="row" class="px-4 py-2 font-semibold">Person: normal</th>
                                <td class="px-4 py-2">{{ $m['normal']['normal'] }} <span class="text-sm text-muted">agree</span></td>
                                <td class="px-4 py-2">{{ $m['normal']['suspicious'] }} <span class="text-sm text-muted">false alarm</span></td>
                            </tr>
                            <tr class="border-t border-cream-deep">
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
            <h2 id="disagree-heading" class="text-xl font-semibold">Every disagreement ({{ count($disagreements) }})</h2>
            @if ($disagreements === [])
                <p class="mt-3">The AI agreed with the person on every case.</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach ($disagreements as $r)
                        <li class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-cream-deep">
                            <p class="font-semibold">
                                {{ $r['case_id'] }} · ${{ $r['amount'] }} at {{ $r['merchant'] }}, {{ $r['local_time'] }}
                                <span class="ml-2 rounded-full bg-cream-deep px-2.5 py-0.5 text-xs">{{ $r['split'] }}</span>
                            </p>
                            <div class="mt-3 grid gap-4 md:grid-cols-2">
                                <div>
                                    <h3 class="text-sm font-semibold uppercase tracking-wider text-muted">Person: {{ $r['my_label'] }}</h3>
                                    <p class="mt-1">{{ $r['notes'] }}</p>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold uppercase tracking-wider text-muted">AI: {{ $r['ai_decision'] }}, risk {{ $r['risk_score'] }}/100</h3>
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
                <h2 id="history-heading" class="text-xl font-semibold">Earlier runs</h2>
                <p class="mt-1 text-sm text-muted">Every run is kept. A new prompt version means the prompt was changed between runs.</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full rounded-xl bg-white text-left ring-1 ring-cream-deep">
                        <caption class="sr-only">Agreement for every eval run</caption>
                        <thead>
                            <tr class="text-sm text-muted">
                                <th scope="col" class="px-4 py-2">Run</th>
                                <th scope="col" class="px-4 py-2">Prompt version</th>
                                <th scope="col" class="px-4 py-2">Dev</th>
                                <th scope="col" class="px-4 py-2">Holdout</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($run['history'] as $h)
                                <tr class="border-t border-cream-deep">
                                    <td class="px-4 py-2">{{ Carbon::parse($h['run_at'])->format('M j, Y g:i A') }}</td>
                                    <td class="px-4 py-2"><code>{{ $h['prompt_version'] }}</code></td>
                                    <td class="px-4 py-2">{{ $h['dev_pct'] }}%</td>
                                    <td class="px-4 py-2">{{ $h['holdout_pct'] }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif
</div>
@endsection
