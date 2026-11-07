{{-- The assumptions behind a projection (ADR 0030), with low/base/high and where each comes from.
     Needs $keys (assumption keys to list) and $case (the column to highlight). --}}
@php
    use App\Support\Projections\Figures;

    $all = config('projections.assumptions');
    $link = 'font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover';
@endphp
<x-rtable class="mt-3" label="Assumptions table">
    <table data-rtable="stack" class="w-full min-w-3xl text-left text-sm">
        <caption class="sr-only">Each assumption's low, base and high value, and its source</caption>
        <thead>
            <tr>
                <th scope="col" class="py-3 pr-4">Assumption</th>
                @foreach (config('projections.cases') as $key => $name)
                    <th scope="col" @class(['px-4 py-3 text-right font-semibold', 'text-primary' => $key === $case])>{{ $name }}</th>
                @endforeach
                <th scope="col" class="py-3 pl-4">Source</th>
            </tr>
        </thead>
        <tbody class="tabular divide-y divide-line">
            @foreach ($keys as $key)
                @php $a = $all[$key]; @endphp
                <tr>
                    <th scope="row" data-primary class="py-3 pr-4 align-top font-bold">
                        {{ $a['label'] }}
                        <span class="block font-normal text-muted">{{ $a['unit'] }}</span>
                    </th>
                    @foreach (array_keys(config('projections.cases')) as $c)
                        <td data-label="{{ config('projections.cases')[$c] }}" @class(['ledger-num px-4 py-3 text-right align-top', 'font-bold text-primary' => $c === $case && $a['varies']])>{{ Figures::assumption($a, $c) }}</td>
                    @endforeach
                    <td data-label="Source" class="py-3 pl-4 align-top">
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
