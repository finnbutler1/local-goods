{{-- Sale, Local Goods fee, You receive (ADR 0029), from a redemption's own fee snapshot or, before a pay code
     is confirmed, the current fee. Only for people who see fee figures (never judges: MerchantFee::showsFiguresTo). --}}
<dl class="{{ $class ?? 'mt-1 grid w-max grid-cols-[auto_auto] gap-x-3 text-sm' }}" data-fee-lines>
    <dt class="text-muted">Sale</dt><dd class="tabular text-right">{{ \App\Support\Money::format($sale) }}</dd>
    <dt class="text-muted">Local Goods fee</dt><dd class="tabular text-right">{{ $fee > 0 ? '−'.\App\Support\Money::format($fee) : \App\Support\Money::format(0) }}</dd>
    <dt class="font-semibold">You receive</dt><dd class="tabular text-right font-semibold">{{ \App\Support\Money::format($sale - $fee) }}</dd>
</dl>
