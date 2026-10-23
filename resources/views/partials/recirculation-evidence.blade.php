{{-- The labelled "roughly 3x" line (ADR 0024). An outside study's figure, never a Local Goods result:
     the label and the source always render with it. Numbers come from config/impact.php. --}}
@php
    $source = config('impact.recirculation');
    $independent = round(100 * $source['independent_rate'], 1);
    $chain = round(100 * $source['chain_rate'], 1);
    $ratio = round($source['independent_rate'] / $source['chain_rate'], 1);
    $link = 'font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary';
@endphp
<div data-recirculation-evidence>
    <p class="text-sm font-semibold uppercase tracking-widest text-muted">Estimate from an outside study, not measured by Local Goods</p>
    <p class="mt-3 max-w-3xl text-lg text-ink">
        Money spent at independent local businesses tends to recirculate roughly 3x more in the local economy than money spent at chains,
        an effect often called the local multiplier.
    </p>
    <p class="mt-3 max-w-3xl text-sm text-muted">
        In the study, independent retailers and restaurants recirculated {{ $independent }}% of their revenue locally, against {{ $chain }}%
        for chains: about {{ $ratio }} times as much. Local Goods has not measured this in the Golden Triangle. Real results will be
        measured in a pilot.
    </p>
    <p class="mt-3 max-w-3xl text-sm text-ink">
        Source: {{ $source['publisher'] }}, “{{ $source['title'] }}” (with {{ $source['partner'] }}), data from {{ $source['data_years'] }},
        {{ $source['sample'] }}.
        <a href="{{ $source['url'] }}" class="{{ $link }}" rel="noopener">Read the Publishers Weekly report on the study</a>.
    </p>
</div>
