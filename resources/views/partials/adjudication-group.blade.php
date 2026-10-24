{{-- One group of blind adjudicators (ADR 0011, 0019, 0020): the all/unseen cards,
     the by-split and by-pattern tables and, when $showCases, every disputed case.
     Every figure shows the votes behind it; a case with fewer than
     AdjudicationResults::MIN_VOTES votes is "too few votes to call".
     Needs $all, $unseen, $noun ("Adjudicator" or "Contact"), $plural, $showNames, $showCases. --}}
@php
    use App\Services\Fraud\FraudEvalPatterns;
    use App\Services\Fraud\FraudEvalReport;
    use App\Services\Fraud\FraudEvalRunner;
    // Everyone in the personal-contact group has the same type, so it isn't repeated there.
    $who = fn ($v) => ($showNames ? $v['name'] : "{$noun} {$v['number']}")
        .($v['type'] && $noun === 'Adjudicator' ? " ({$v['type']})" : '');
    $sided = ['ai' => ['ok', 'Sided with the AI'], 'finn' => ['accent', 'Sided with Finn'], 'split' => ['neutral', 'Split'], 'too_few' => ['neutral', 'Too few votes to call']];
    $withVotes = fn ($row, $side) => $row[$side].' ('.$row["{$side}_votes"].' '.Str::plural('vote', $row["{$side}_votes"]).')';
    $minVotes = \App\Services\Adjudication\AdjudicationResults::MIN_VOTES;
    $th = 'px-4 py-2';
    $label = ucfirst($plural);
@endphp

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ([[$all, "All {$plural}"], [$unseen, "Only {$plural} who hadn't looked at the eval page before"]] as [$group, $title])
            <x-card>
                <h3 class="text-lg font-semibold">{{ $title }} <span class="text-muted">({{ count($group['adjudicators']) }})</span></h3>
                <p class="mt-2 font-display text-xl font-semibold text-primary">{{ $group['sentence'] }}</p>
                @if (! $showCases && count($group['cases']) > 0)
                    @php $counts = array_column($group['cases'], 'vote_count'); @endphp
                    <p class="mt-2 text-sm text-muted">
                        Votes per disputed case so far: {{ min($counts) }} to {{ max($counts) }}.
                        {{ count(array_filter($counts, fn ($n) => $n >= $minVotes)) }} of {{ count($counts) }} cases have the {{ $minVotes }} or more needed to call.
                    </p>
                @endif
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
        <x-rtable :card="false" label="{{ $label }}: disputed cases by {{ strtolower($heading) }} table">
            <table data-rtable="stack" class="tabular w-full min-w-2xl rounded-2xl bg-surface text-left ring-1 ring-line/70">
                <caption class="px-4 pt-4 pb-1 text-left font-semibold">{{ $label }}: disputed cases by {{ strtolower($heading) }}</caption>
                <thead>
                    <tr class="bg-band/60 text-sm text-muted">
                        <th scope="col" class="{{ $th }}">{{ $heading }}</th>
                        <th scope="col" class="{{ $th }}">Disputed cases (votes)</th>
                        <th scope="col" class="{{ $th }}">All: with the AI</th>
                        <th scope="col" class="{{ $th }}">All: with Finn</th>
                        <th scope="col" class="{{ $th }}">All: split</th>
                        <th scope="col" class="{{ $th }}">All: too few votes to call</th>
                        <th scope="col" class="{{ $th }}">Hadn't seen eval: AI / Finn / split / too few</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($all[$key] as $name => $row)
                        @php $u = $unseen[$key][$name] ?? null; @endphp
                        <tr class="border-t border-line/70">
                            <th scope="row" data-primary class="{{ $th }} font-semibold">{{ $names[$name] ?? $name }}</th>
                            <td data-label="Disputed cases (votes)" class="{{ $th }}">{{ $row['cases'] }} ({{ $row['votes'] }} {{ Str::plural('vote', $row['votes']) }})</td>
                            <td data-label="All: with the AI" class="{{ $th }}">{{ $withVotes($row, 'ai') }}</td>
                            <td data-label="All: with Finn" class="{{ $th }}">{{ $withVotes($row, 'finn') }}</td>
                            <td data-label="All: split" class="{{ $th }}">{{ $withVotes($row, 'split') }}</td>
                            <td data-label="All: too few votes to call" class="{{ $th }}">{{ $withVotes($row, 'too_few') }}</td>
                            <td data-label="Hadn't seen eval: AI / Finn / split / too few" class="{{ $th }}">{{ count($unseen['adjudicators']) > 0 && $u ? "{$u['ai']} / {$u['finn']} / {$u['split']} / {$u['too_few']}" : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-rtable>
    @endforeach

    @if ($showCases)
        <div>
            <h3 class="text-xl font-semibold">{{ $label }}: every disputed case ({{ count($all['cases']) }})</h3>
            <ul class="mt-3 space-y-3">
                @foreach ($all['cases'] as $c)
                    @php [$tone, $badge] = $sided[$c['sided_with']]; @endphp
                    <li class="rounded-2xl bg-surface p-5 ring-1 ring-line/70">
                        <p class="flex flex-wrap items-center gap-2 font-semibold">
                            {{ $c['case_id'] }} · ${{ $c['facts']['amount'] }} at {{ $c['facts']['merchant'] }}, {{ $c['facts']['local_time'] }}
                            <x-badge>{{ $c['split'] }}</x-badge>
                            <x-badge :tone="$tone">{{ $badge }}</x-badge>
                        </p>
                        <p class="mt-1 text-sm text-muted">
                            Finn: {{ $c['my_label'] }} · AI: {{ $c['ai_label'] }} ·
                            {{ $c['vote_count'] }} {{ Str::plural('vote', $c['vote_count']) }}: {{ $c['suspicious'] }} suspicious, {{ $c['normal'] }} normal ·
                            Majority: {{ $c['sided_with'] === 'too_few' ? "not called (fewer than {$minVotes} votes)" : ($c['majority'] ?? 'none (tie)') }}
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
