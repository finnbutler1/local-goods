{{-- District impact by year (ADR 0030): what the program could mean for independent merchants.
     No Local Goods fee or revenue figures. Needs $district (Projection::districtImpact()). --}}
@php use App\Support\Projections\Figures; @endphp
<x-rtable class="mt-3" label="District impact by year">
    <table data-rtable="stack" class="w-full min-w-2xl text-left text-sm">
        <caption class="sr-only">Projected district impact by year</caption>
        <thead class="border-b border-line/70 text-muted">
            <tr>
                <th scope="col" class="px-4 py-3 font-semibold">Year</th>
                <th scope="col" class="px-4 py-3 text-right font-semibold">BIDs</th>
                <th scope="col" class="px-4 py-3 text-right font-semibold">Employers</th>
                <th scope="col" class="px-4 py-3 text-right font-semibold">Participating employees</th>
                <th scope="col" class="px-4 py-3 text-right font-semibold">Merchants</th>
                <th scope="col" class="px-4 py-3 text-right font-semibold">Spent at independent merchants</th>
                <th scope="col" class="px-4 py-3 text-right font-semibold">Visits</th>
                <th scope="col" class="px-4 py-3 text-right font-semibold">Average per merchant</th>
            </tr>
        </thead>
        <tbody class="tabular divide-y divide-line/70">
            @foreach ($district as $year)
                <tr>
                    <th scope="row" data-primary class="px-4 py-3 font-semibold">Year {{ $year['year'] }}</th>
                    <td data-label="BIDs" class="px-4 py-3 text-right">{{ Figures::count($year['bids']) }}</td>
                    <td data-label="Employers" class="px-4 py-3 text-right">{{ Figures::count($year['employers']) }}</td>
                    <td data-label="Participating employees" class="px-4 py-3 text-right">{{ Figures::count($year['employees']) }}</td>
                    <td data-label="Merchants" class="px-4 py-3 text-right">{{ Figures::count($year['merchants']) }}</td>
                    <td data-label="Spent at independent merchants" class="px-4 py-3 text-right">{{ Figures::dollars($year['spend']) }}</td>
                    <td data-label="Visits" class="px-4 py-3 text-right">{{ Figures::count($year['visits']) }}</td>
                    <td data-label="Average per merchant" class="px-4 py-3 text-right">{{ Figures::dollars($year['per_merchant']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-rtable>
<p class="mt-2 text-sm text-muted">
    Counts are at the end of each year; dollars and visits are the year's total. A visit is one purchase.
    The average per merchant is the year's spending divided by the average number of participating merchants that year.
</p>
