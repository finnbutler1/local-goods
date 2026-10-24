{{--
    The DC sales tax on a redemption or pay code (ADR 0027), from its own snapshot only, never from
    config: a later rate change never rewrites a receipt. $tax is a TaxBreakdown, or null for a row
    from before tax display. $compact gives one short line for lists. Colors come from the parent.
--}}
@php
    use App\Support\Money;
    use App\Support\TaxBreakdown;
    $compact ??= false;
    $class ??= '';
@endphp
@if ($tax?->isAdded())
    @if ($compact)
        <p class="{{ $class }}" data-tax-breakdown>
            Subtotal <span class="tabular">{{ Money::format($tax->subtotalCents) }}</span>
            @foreach ($tax->lines as $line)
                · {{ TaxBreakdown::lineLabel($line['label'], $line['rate_bps']) }} <span class="tabular">{{ Money::format($line['tax_cents']) }}</span>
            @endforeach
        </p>
    @else
        <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 {{ $class }}" data-tax-breakdown>
            <dt>Subtotal</dt>
            <dd class="tabular text-right">{{ Money::format($tax->subtotalCents) }}</dd>
            @foreach ($tax->lines as $line)
                <dt>
                    {{ TaxBreakdown::lineLabel($line['label'], $line['rate_bps']) }}
                    @if ($line['taxable_cents'] !== $tax->subtotalCents)
                        <span class="whitespace-nowrap">on <span class="tabular">{{ Money::format($line['taxable_cents']) }}</span></span>
                    @endif
                </dt>
                <dd class="tabular text-right">{{ Money::format($line['tax_cents']) }}</dd>
            @endforeach
            <dt class="font-semibold">Total</dt>
            <dd class="tabular text-right font-semibold">{{ Money::format($tax->totalCents) }}</dd>
        </dl>
    @endif
@elseif ($tax)
    <p class="{{ $class }}" data-tax-note>Amount includes any tax charged by the shop.</p>
@else
    <p class="{{ $class }}" data-tax-note>Tax not itemized (before tax display)</p>
@endif
