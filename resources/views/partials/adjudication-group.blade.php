{{-- One group of blind adjudicators (ADR 0011, 0019): the all/unseen cards, the
     by-split and by-pattern tables and, when $showCases, every disputed case.
     Needs $all, $unseen, $noun ("Adjudicator" or "Contact"), $plural, $showNames, $showCases. --}}
@php
    use App\Services\Fraud\FraudEvalPatterns;
    use App\Services\Fraud\FraudEvalReport;
    use App\Services\Fraud\FraudEvalRunner;
    // Everyone in the personal-contact group has the same type, so it isn't repeated there.
    $who = fn ($v) => ($showNames ? $v['name'] : "{$noun} {$v['number']}")
        .($v['type'] && $noun === 'Adjudicator' ? " ({$v['type']})" : '');
    $sided = ['ai' => ['ok', 'Sided with the AI'], 'finn' => ['amber', 'Sided with Finn'], 'split' => ['neutral', 'Split']];
    $th = 'px-4 py-2';
    $label = ucfirst($plural);
@endphp

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ([[$all, "All {$plural}"], [$unseen, "Only {$plural} who hadn't looked at the eval page before"]] as [$group, $title])
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
        <div class="scroll-region" role="region" tabindex="0" aria-label="{{ $label }}: disputed cases by {{ strtolower($heading) }} table">
            <table class="tabular w-full min-w-2xl rounded-2xl bg-paper text-left ring-1 ring-line/70">
                <caption class="px-4 pt-4 pb-1 text-left font-semibold">{{ $label }}: disputed cases by {{ strtolower($heading) }}</caption>
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
            <h3 class="text-xl font-semibold">{{ $label }}: every disputed case ({{ count($all['cases']) }})</h3>
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
