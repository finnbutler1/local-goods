{{-- Who has started and submitted the current adjudication set. Needs $current. --}}
@php $participants = $current->participants()->with('user')->orderBy('id')->get(); @endphp
<div class="overflow-x-auto">
    <table class="tabular w-full min-w-2xl rounded-2xl bg-paper text-left ring-1 ring-line/70">
        <caption class="px-4 pt-4 pb-1 text-left font-semibold">Adjudicators ({{ $participants->count() }})</caption>
        <thead>
            <tr class="bg-cream-deep/60 text-sm text-muted">
                <th scope="col" class="px-4 py-2">Reviewer</th>
                <th scope="col" class="px-4 py-2">Type</th>
                <th scope="col" class="px-4 py-2">Saw eval before</th>
                <th scope="col" class="px-4 py-2">Votes</th>
                <th scope="col" class="px-4 py-2">Submitted</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($participants as $p)
                <tr class="border-t border-line/70">
                    <th scope="row" class="px-4 py-2 font-semibold">{{ $p->user->name }}</th>
                    <td class="px-4 py-2">{{ $p->user->reviewer_type?->label() }}</td>
                    <td class="px-4 py-2">{{ $p->seen_eval_before ? 'yes' : 'no' }}</td>
                    <td class="px-4 py-2">{{ \App\Models\AdjudicationVote::where('user_id', $p->user_id)->whereIn('adjudication_case_id', $current->cases()->select('id'))->count() }} of {{ $current->cases()->count() }}</td>
                    <td class="px-4 py-2">{{ $p->submitted_at ? $p->submitted_at->setTimezone('America/New_York')->format('M j, g:i A') : 'Not yet' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-3 text-muted">Nobody has started yet. Named BID and contest-judge reviewers see "Help check the AI" on their home page.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
