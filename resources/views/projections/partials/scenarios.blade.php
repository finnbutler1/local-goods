{{-- BIDs taking part at each year's end in every scenario (ADR 0030), with sources. --}}
@php $link = 'font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary'; @endphp
<x-rtable class="mt-4" label="BIDs by scenario">
    <table data-rtable="stack" class="w-full min-w-2xl text-left text-sm">
        <caption class="sr-only">BIDs taking part at the end of each year, by scenario</caption>
        <thead class="border-b border-line/70 text-muted">
            <tr>
                <th scope="col" class="px-4 py-3 font-semibold">Scenario</th>
                @foreach ([1, 2, 3, 4] as $y)<th scope="col" class="px-4 py-3 text-right font-semibold">End of year {{ $y }}</th>@endforeach
                <th scope="col" class="px-4 py-3 font-semibold">Source</th>
            </tr>
        </thead>
        <tbody class="tabular divide-y divide-line/70">
            @foreach (config('projections.scenarios') as $s)
                <tr>
                    <th scope="row" data-primary class="px-4 py-3 font-semibold">{{ $s['name'] }}</th>
                    @foreach ($s['bids_by_year_end'] as $i => $bids)<td data-label="End of year {{ $i + 1 }}" class="px-4 py-3 text-right">{{ $bids }} {{ Str::plural('BID', $bids) }}</td>@endforeach
                    <td data-label="Source" class="px-4 py-3">
                        @if ($s['source_url'])<a href="{{ $s['source_url'] }}" class="{{ $link }}" rel="noopener">{{ $s['source'] }}</a>@else<span class="font-semibold">{{ $s['source'] }}</span>@endif
                        <span class="mt-1 block text-muted">{{ $s['note'] }}</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-rtable>
