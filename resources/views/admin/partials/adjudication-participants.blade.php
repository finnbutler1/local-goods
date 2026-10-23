{{-- Who has started and submitted the current adjudication set, in two tables:
     BID and contest judges (the headline) and personal contacts (ADR 0019),
     never counted together. Personal contacts are shown by initials. Needs $current. --}}
@php
    [$headline, $personal] = $current->participants()->with(['user', 'assignments'])->orderBy('id')->get()
        ->partition(fn ($p) => ! $p->user->isPersonalContact());
    $caseIds = $current->cases()->select('id');
    $caseCount = $current->cases()->count();
@endphp
@foreach ([
    [$headline, 'BID and contest judges', 'Nobody has started yet. Named BID and contest-judge reviewers see "Help check the AI" on their home page.'],
    [$personal, 'Personal contacts (know Finn), not counted in the headline', 'No personal contacts have started yet. Their magic link opens the voting page directly.'],
] as [$participants, $title, $empty])
    <div class="scroll-region" role="region" tabindex="0" aria-label="{{ $title }} table">
        <table class="tabular w-full min-w-2xl rounded-2xl bg-surface text-left ring-1 ring-line/70">
            <caption class="px-4 pt-4 pb-1 text-left font-semibold">{{ $title }} ({{ $participants->count() }})</caption>
            <thead>
                <tr class="bg-band/60 text-sm text-muted">
                    <th scope="col" class="px-4 py-2">Reviewer</th>
                    <th scope="col" class="px-4 py-2">Type</th>
                    <th scope="col" class="px-4 py-2">Saw eval before</th>
                    <th scope="col" class="px-4 py-2">Batches submitted</th>
                    <th scope="col" class="px-4 py-2">Votes that count</th>
                    <th scope="col" class="px-4 py-2">Finished</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($participants as $p)
                    <tr class="border-t border-line/70">
                        <th scope="row" class="px-4 py-2 font-semibold">{{ $p->user->adminLabel() }}</th>
                        <td class="px-4 py-2">{{ $p->user->reviewer_type?->label() }}</td>
                        <td class="px-4 py-2">{{ $p->seen_eval_before ? 'yes' : 'no' }}</td>
                        @php $submitted = $p->assignments->whereNotNull('submitted_at'); @endphp
                        @if ($p->assignments->isEmpty() && $p->submitted_at)
                            {{-- Pressed Submit all before batches (ADR 0020): every vote counts. --}}
                            <td class="px-4 py-2">Before batches</td>
                            <td class="px-4 py-2">{{ \App\Models\AdjudicationVote::where('user_id', $p->user_id)->whereIn('adjudication_case_id', $caseIds)->count() }} of {{ $caseCount }}</td>
                        @else
                            <td class="px-4 py-2">{{ $submitted->pluck('batch')->unique()->count() }}</td>
                            <td class="px-4 py-2">{{ $submitted->count() }} of {{ $caseCount }}</td>
                        @endif
                        <td class="px-4 py-2">{{ $p->submitted_at ? $p->submitted_at->setTimezone('America/New_York')->format('M j, g:i A') : 'Not yet' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-3 text-muted">{{ $empty }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endforeach
