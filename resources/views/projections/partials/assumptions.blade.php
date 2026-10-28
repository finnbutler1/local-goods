{{-- The assumptions behind a projection (ADR 0030), with low/base/high and where each comes from.
     Needs $keys (assumption keys to list) and $case (the column to highlight). --}}
@php
    use App\Support\Projections\Figures;

    $all = config('projections.assumptions');
    $link = 'font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary';
@endphp
<x-rtable class="mt-3" label="Assumptions table">
    <table data-rtable="stack" class="w-full min-w-3xl text-left text-sm">
        <caption class="sr-only">Each assumption's low, base and high value, and its source</caption>
        <thead class="border-b border-line/70 text-muted">
            <tr>
                <th scope="col" class="px-4 py-3 font-semibold">Assumption</th>
                @foreach (config('projections.cases') as $key => $name)
                    <th scope="col" @class(['px-4 py-3 text-right font-semibold', 'text-primary' => $key === $case])>{{ $name }}</th>
                @endforeach
                <th scope="col" class="px-4 py-3 font-semibold">Source</th>
            </tr>
        </thead>
        <tbody class="tabular divide-y divide-line/70">
            @foreach ($keys as $key)
                @php $a = $all[$key]; @endphp
                <tr>
                    <th scope="row" data-primary class="px-4 py-3 align-top font-semibold">
                        {{ $a['label'] }}
                        <span class="block font-normal text-muted">{{ $a['unit'] }}</span>
                    </th>
                    @foreach (array_keys(config('projections.cases')) as $c)
                        <td data-label="{{ config('projections.cases')[$c] }}" @class(['px-4 py-3 text-right align-top', 'font-semibold text-primary' => $c === $case && $a['varies']])>{{ Figures::assumption($a, $c) }}</td>
                    @endforeach
                    <td data-label="Source" class="px-4 py-3 align-top">
                        @if ($a['source_url'])
                            <a href="{{ $a['source_url'] }}" class="{{ $link }}" rel="noopener">{{ $a['source'] }}</a>
                        @else
                            <span class="font-semibold">{{ $a['source'] }}</span>
                        @endif
                        @if ($a['note'] !== '')
                            <span class="mt-1 block text-muted">{{ $a['note'] }}</span>
                        @endif
                        <span class="mt-1 block text-xs text-muted">Checked {{ $a['verified_on'] }}</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-rtable>
