{{-- Blind adjudication results (ADR 0011). Needs $adjudication (AdjudicationResults::for),
     $adjudicationSet, $showNames (admin only) and $showCases (admin, or once voting closes). --}}
@php
    use App\Services\Fraud\FraudEvalPatterns;
    use App\Services\Fraud\FraudEvalReport;
    use App\Services\Fraud\FraudEvalRunner;
    $all = $adjudication['all'];
    $unseen = $adjudication['unseen'];
    $who = fn ($v) => ($showNames ? $v['name'] : "Adjudicator {$v['number']}")
        .($v['type'] ? " ({$v['type']})" : '');
    $sided = ['ai' => ['ok', 'Sided with the AI'], 'finn' => ['amber', 'Sided with Finn'], 'split' => ['neutral', 'Split']];
    $th = 'px-4 py-2';
@endphp

<section id="adjudication" aria-labelledby="adjudication-heading" class="scroll-mt-24 space-y-5">
    <div class="max-w-3xl">
        <h2 id="adjudication-heading" class="text-2xl font-semibold text-forest">Blind adjudication of the disagreements</h2>
        <p class="mt-1 text-muted">
            Finn labeled every case, so where Finn and the AI disagree Finn may be the one who is wrong.
            Named BID staff and contest judges voted on those cases without seeing Finn's label, the AI's call or each other's votes.
            Cases where both agreed were mixed in as blind controls. Only adjudicators who submitted all their votes count.
            This is reported on its own: it does not change any label or any number above.
        </p>
        <p class="mt-2 text-sm text-muted">
            Version {{ $adjudicationSet->version }} ·
            {{ count($all['adjudicators']) }} {{ Str::plural('adjudicator', count($all['adjudicators'])) }} voted
            ({{ collect($all['by_type'])->map(fn ($n, $type) => "{$n} {$type}")->implode(', ') }}) ·
            {{ $adjudicationSet->isOpen() ? 'voting still open' : 'voting closed' }}
        </p>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ([[$all, 'All adjudicators'], [$unseen, "Only adjudicators who hadn't looked at the eval page before"]] as [$group, $title])
            <x-card>
                <h3 class="text-lg font-semibold">{{ $title }} <span class="text-muted">({{ count($group['adjudicators']) }})</span></h3>
                <p class="mt-2 font-display text-xl font-semibold text-forest">{{ $group['sentence'] }}</p>
                @if ($group['controls']['of'] > 0)
                    <p class="mt-2 text-sm text-muted">
                        Reliability check: on the {{ $group['controls']['cases'] }} control cases, their votes matched the shared label
                        {{ FraudEvalReport::rate($group['controls']) }}.
                    </p>
                @endif
            </x-card>
        @endforeach
    </div>

    @foreach (['by_split' => ['Split', FraudEvalRunner::SPLITS], 'by_tag' => ['Pattern', FraudEvalPatterns::TAGS]] as $key => [$heading, $names])
        <div class="overflow-x-auto">
            <table class="tabular w-full min-w-2xl rounded-2xl bg-paper text-left ring-1 ring-line/70">
                <caption class="px-4 pt-4 pb-1 text-left font-semibold">Disputed cases by {{ strtolower($heading) }}</caption>
                <thead>
                    <tr class="bg-cream-deep/60 text-sm text-muted">
                        <th scope="col" class="{{ $th }}">{{ $heading }}</th>
                        <th scope="col" class="{{ $th }}">Disputed cases</th>
                        <th scope="col" class="{{ $th }}">All: with the AI</th>
                        <th scope="col" class="{{ $th }}">All: with Finn</th>
                        <th scope="col" class="{{ $th }}">All: split</th>
                        <th scope="col" class="{{ $th }}">Hadn't seen eval: AI / Finn / split</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($all[$key] as $name => $row)
                        @php $u = $unseen[$key][$name] ?? null; @endphp
                        <tr class="border-t border-line/70">
                            <th scope="row" class="{{ $th }} font-semibold">{{ $names[$name] ?? $name }}</th>
                            <td class="{{ $th }}">{{ $row['cases'] }}</td>
                            <td class="{{ $th }}">{{ $row['ai'] }}</td>
                            <td class="{{ $th }}">{{ $row['finn'] }}</td>
                            <td class="{{ $th }}">{{ $row['split'] }}</td>
                            <td class="{{ $th }}">{{ count($unseen['adjudicators']) > 0 && $u ? "{$u['ai']} / {$u['finn']} / {$u['split']}" : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    @if ($showCases)
        <div>
            <h3 class="text-xl font-semibold">Every disputed case ({{ count($all['cases']) }})</h3>
            <ul class="mt-3 space-y-3">
                @foreach ($all['cases'] as $c)
                    @php [$tone, $label] = $sided[$c['sided_with']]; @endphp
                    <li class="rounded-2xl bg-paper p-5 ring-1 ring-line/70">
                        <p class="flex flex-wrap items-center gap-2 font-semibold">
                            {{ $c['case_id'] }} · ${{ $c['facts']['amount'] }} at {{ $c['facts']['merchant'] }}, {{ $c['facts']['local_time'] }}
                            <x-badge>{{ $c['split'] }}</x-badge>
                            <x-badge :tone="$tone">{{ $label }}</x-badge>
                        </p>
                        <p class="mt-1 text-sm text-muted">
                            Finn: {{ $c['my_label'] }} · AI: {{ $c['ai_label'] }} ·
                            Votes: {{ $c['suspicious'] }} suspicious, {{ $c['normal'] }} normal ·
                            Majority: {{ $c['majority'] ?? 'none (tie)' }}
                        </p>
                        <ul class="mt-3 space-y-2 border-t border-line/70 pt-3">
                            @foreach ($c['votes'] as $v)
                                <li>
                                    <span class="font-semibold">{{ $who($v) }}</span>
                                    <span class="text-sm text-muted">(saw eval before: {{ $v['seen_eval_before'] ? 'yes' : 'no' }})</span>:
                                    <strong>{{ $v['label'] }}</strong>. {{ $v['reason'] }}
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        </div>
    @else
        <p class="text-muted">Each case's votes and reasons appear here once voting closes, so later adjudicators can't see earlier votes.</p>
    @endif
</section>
