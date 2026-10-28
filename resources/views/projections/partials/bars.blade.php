{{-- A small bar chart for projections (ADR 0030), the same pattern as the BID impact page:
     the bars are hidden from screen readers because the table next to them has every number.
     Needs $bars: list of ['label' => 'Year 1', 'value' => float, 'text' => '$28k'], and
     optional $negative (bars below zero drawn in the danger color). --}}
@php
    $max = max(1, max(array_map(fn ($b) => abs($b['value']), $bars)));
@endphp
<div class="mt-4 flex h-40 items-end gap-2 border-b border-muted/40 sm:gap-4" aria-hidden="true">
    @foreach ($bars as $bar)
        <div class="group relative flex h-full flex-1 items-end justify-center" data-bar>
            <div @class(['w-full max-w-16 rounded-t-[4px] transition-colors', 'bg-danger' => $bar['value'] < 0, 'bg-primary group-hover:bg-primary-hover group-data-open:bg-primary-hover' => $bar['value'] >= 0])
                 style="height: {{ max(2, round(100 * abs($bar['value']) / $max)) }}%"></div>
            <div @class(['pointer-events-none absolute bottom-full z-10 mb-2 hidden w-max rounded-lg bg-night px-2.5 py-1.5 text-xs text-on-night shadow group-hover:block group-data-open:block', 'left-0' => $loop->first, 'right-0' => $loop->last, 'left-1/2 -translate-x-1/2' => ! $loop->first && ! $loop->last])>
                <span class="font-semibold">{{ $bar['label'] }}</span><br>{{ $bar['text'] }}
            </div>
        </div>
    @endforeach
</div>
<div class="mt-1.5 flex gap-2 text-center text-xs text-muted sm:gap-4" aria-hidden="true">
    @foreach ($bars as $bar)
        <span class="flex-1">{{ $bar['label'] }}<br><span class="tabular font-semibold text-ink">{{ $bar['text'] }}</span></span>
    @endforeach
</div>
