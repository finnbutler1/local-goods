@php
    $when = fn ($t) => $t ? $t->setTimezone('America/New_York')->format('M j, g:i A') : '—';
    $select = 'block w-full rounded-xl border-2 border-muted bg-paper px-3 py-2.5 focus-visible:border-forest';
@endphp

<x-app-shell title="Adjudication" active="adjudication" context="Local Goods platform">
<div class="space-y-10">
    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Platform admin</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Blind adjudication</h1>
        <p class="mt-3 text-lg text-muted">
            Named BID and contest-judge reviewers vote, blind, on the eval cases where your label and the AI disagree.
            The same number of cases where you agreed are mixed in as controls, so nobody can tell which cases are disputed.
            Adjudicators see only the facts of each case: never your label or note, the AI's call, or anyone else's vote.
            Results are reported on their own and never change your labels or the headline numbers.
        </p>
    </header>

    <div aria-live="polite">
        @if (session('status'))
            <p class="rounded-2xl border-2 border-ok bg-ok-bg p-4 font-semibold text-ok">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="rounded-2xl border-2 border-hold bg-hold-bg p-4 font-semibold text-hold">Nothing changed: {{ $errors->first() }}</p>
        @endif
    </div>

    @if ($draft)
        <section aria-labelledby="draft-heading" class="space-y-5">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 id="draft-heading" class="text-2xl font-semibold text-forest">Draft: version {{ $draft->version }}</h2>
                    <p class="mt-1 text-muted">
                        {{ $draftCases->where('kind', 'disputed')->count() }} disputed and {{ $draftCases->where('kind', 'control')->count() }} control cases.
                        Facts and labels come from the eval run of {{ $draft->source_run ? \Illuminate\Support\Carbon::parse($draft->source_run)->format('M j, Y g:i A') : 'the latest run' }}
                        (prompt <code>{{ $draft->prompt_version }}</code>).
                    </p>
                </div>
                <form method="POST" action="{{ route('admin.adjudication.publish') }}"
                      onsubmit="return confirm('Publish version {{ $draft->version }}? Its cases are locked once published; changing them needs a new version.');">
                    @csrf
                    <x-button type="submit" variant="accent">Publish and lock</x-button>
                </form>
            </div>

            @foreach (['disputed' => 'Disputed cases (your label and the AI disagree)', 'control' => 'Blind controls (you and the AI agree)'] as $kind => $title)
                @php $rows = $draftCases->where('kind', $kind); @endphp
                <div>
                    <h3 class="text-xl font-semibold">{{ $title }} <span class="text-muted">({{ $rows->count() }})</span></h3>
                    @foreach ($kind === 'disputed' ? $rows->groupBy('split')->sortKeys() : ['' => $rows] as $split => $group)
                        @if ($split !== '')
                            <h4 class="mt-3 font-sans text-sm font-semibold uppercase tracking-widest text-muted">{{ $splits[$split] ?? $split }}</h4>
                        @endif
                        <div class="mt-2 overflow-x-auto">
                            <table class="tabular w-full min-w-2xl rounded-2xl bg-paper text-left ring-1 ring-line/70">
                                <caption class="sr-only">{{ $title }} {{ $split !== '' ? '('.($splits[$split] ?? $split).')' : '' }}</caption>
                                <thead>
                                    <tr class="bg-cream-deep/60 text-sm text-muted">
                                        <th scope="col" class="px-4 py-2">Case</th>
                                        <th scope="col" class="px-4 py-2">Split</th>
                                        <th scope="col" class="px-4 py-2">Redemption</th>
                                        <th scope="col" class="px-4 py-2">Your label</th>
                                        <th scope="col" class="px-4 py-2">AI</th>
                                        <th scope="col" class="px-4 py-2"><span class="sr-only">Remove</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($group as $case)
                                        <tr class="border-t border-line/70">
                                            <th scope="row" class="px-4 py-2 font-semibold">{{ $case->case_id }}</th>
                                            <td class="px-4 py-2">{{ $splits[$case->split] ?? $case->split }}</td>
                                            <td class="px-4 py-2">${{ $case->facts['amount'] }} at {{ $case->facts['merchant'] }}, {{ $case->facts['local_time'] }}</td>
                                            <td class="px-4 py-2">{{ $case->my_label }}</td>
                                            <td class="px-4 py-2">{{ $case->ai_label }}</td>
                                            <td class="px-4 py-2 text-right">
                                                <form method="POST" action="{{ route('admin.adjudication.cases.destroy', $case) }}">
                                                    @csrf @method('DELETE')
                                                    <x-button type="submit" variant="ghost" size="sm">Remove<span class="sr-only"> {{ $case->case_id }}</span></x-button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="px-4 py-3 text-muted">None.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>
            @endforeach

            @if ($addable !== [])
                <form method="POST" action="{{ route('admin.adjudication.cases.store') }}" class="flex max-w-xl flex-col gap-3 sm:flex-row sm:items-end">
                    @csrf
                    <x-field name="case_id" label="Add a case" class="grow">
                        <select id="case_id" name="case_id" class="{{ $select }}">
                            @foreach ($addable as $c)
                                <option value="{{ $c['case_id'] }}">{{ $c['case_id'] }} ({{ $c['kind'] }}, {{ $splits[$c['split']] ?? $c['split'] }}): ${{ $c['facts']['amount'] }} at {{ $c['facts']['merchant'] }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-button type="submit" variant="secondary">Add</x-button>
                </form>
            @endif
        </section>
    @elseif (! $current)
        <x-card as="section" aria-labelledby="start-heading">
            <h2 id="start-heading" class="text-2xl font-semibold text-forest">Build the adjudication set</h2>
            <p class="mt-2 max-w-2xl text-muted">
                Starts a draft with every eval case where your label and the AI disagree, in every split,
                plus the same number of agreed cases picked at random as blind controls. You can change it before publishing.
            </p>
            <form method="POST" action="{{ route('admin.adjudication.store') }}" class="mt-4">
                @csrf
                <x-button type="submit">Build the draft</x-button>
            </form>
        </x-card>
    @endif

    @if ($current)
        <section aria-labelledby="current-heading" class="space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 id="current-heading" class="text-2xl font-semibold text-forest">
                        Version {{ $current->version }}
                        <x-badge :tone="$current->isOpen() ? 'ok' : 'neutral'" class="ml-2 align-middle font-sans">{{ $current->isOpen() ? 'Voting open' : 'Closed' }}</x-badge>
                    </h2>
                    <p class="mt-1 text-muted">
                        Published {{ $when($current->published_at) }}{{ $current->closed_at ? ', closed '.$when($current->closed_at) : '' }}.
                        Locked: {{ $current->cases()->where('kind', 'disputed')->count() }} disputed and {{ $current->cases()->where('kind', 'control')->count() }} control cases.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($current->isOpen())
                        <form method="POST" action="{{ route('admin.adjudication.close') }}"
                              onsubmit="return confirm('Close voting? Nobody can vote after this, and every vote and reason appears on /eval without names.');">
                            @csrf
                            <x-button type="submit" variant="danger">Close voting</x-button>
                        </form>
                    @endif
                    @unless ($draft)
                        <form method="POST" action="{{ route('admin.adjudication.store') }}">
                            @csrf
                            <x-button type="submit" variant="secondary">Start version {{ $current->version + 1 }}</x-button>
                        </form>
                    @endunless
                </div>
            </div>

            @include('admin.partials.adjudication-participants')
        </section>

        @if ($adjudication)
            @include('partials.adjudication-results', ['adjudicationSet' => $current, 'showNames' => true, 'showCases' => true])
        @else
            <p class="text-muted">Results appear here once a reviewer presses Submit all.</p>
        @endif
    @endif
</div>
</x-app-shell>
