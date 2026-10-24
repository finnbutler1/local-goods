{{--
    The menu and amount fields of a basket form (ADR 0023), shared by Order to go and Pay in person.
    $prefix keeps ids unique when both are on the page ('' for Order to go, 'pay-' for Pay in person).
    Display only: Redemption Fraud Detection sees just the total.
--}}
@php
    use App\Support\Money;
    $itemsError = $errors->first('items') ?: $errors->first('items.*');
    $amountError = $errors->first('amount');
@endphp
@if ($menu->isNotEmpty())
    <fieldset @if ($itemsError) aria-invalid="true" aria-describedby="{{ $prefix }}items-error" @endif>
        <legend class="font-semibold">Menu</legend>
        <p class="text-sm text-muted">Choose how many of each. Leave the rest at 0.</p>
        <ul class="mt-2 divide-y divide-line/70 rounded-xl border-2 border-line">
            @foreach ($menu as $product)
                <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-3 py-3">
                    <div class="min-w-0 flex-1">
                        <label for="{{ $prefix }}qty-{{ $product->id }}" class="font-semibold">{{ $product->name }}</label>
                        <span class="tabular text-muted">· {{ Money::format($product->price_cents) }}</span>
                        @if ($product->description)
                            <p id="{{ $prefix }}qty-{{ $product->id }}-hint" class="text-sm text-muted">{{ $product->description }}</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" hidden data-step="-1" aria-controls="{{ $prefix }}qty-{{ $product->id }}" aria-label="One fewer {{ $product->name }}"
                                class="grid size-11 place-items-center rounded-full border-2 border-primary text-xl font-semibold text-primary hover:bg-primary-tint">−</button>
                        <input id="{{ $prefix }}qty-{{ $product->id }}" name="items[{{ $product->id }}]" type="number" inputmode="numeric"
                               min="0" max="{{ \App\Services\RedemptionBasket::MAX_QUANTITY }}" step="1"
                               value="{{ (int) ($quantities[$product->id] ?? 0) }}" data-price="{{ $product->price_cents }}"
                               @if ($product->description) aria-describedby="{{ $prefix }}qty-{{ $product->id }}-hint" @endif
                               class="tabular w-16 rounded-xl border-2 border-muted bg-surface px-2 py-2 text-center text-lg text-ink focus-visible:border-primary">
                        <button type="button" hidden data-step="1" aria-controls="{{ $prefix }}qty-{{ $product->id }}" aria-label="One more {{ $product->name }}"
                                class="grid size-11 place-items-center rounded-full border-2 border-primary text-xl font-semibold text-primary hover:bg-primary-tint">+</button>
                    </div>
                </li>
            @endforeach
        </ul>
        @if ($itemsError) <p id="{{ $prefix }}items-error" class="mt-1.5 text-sm font-semibold text-hold">{{ $itemsError }}</p> @endif
    </fieldset>
@endif

<div>
    <label for="{{ $prefix }}amount" class="block font-semibold">{{ $menu->isNotEmpty() ? 'Other amount (optional)' : 'Amount' }}</label>
    <div class="mt-1.5 flex items-center rounded-xl border-2 border-muted bg-surface focus-within:border-primary focus-within:outline-3 focus-within:outline-offset-2 focus-within:outline-accent">
        <span class="pl-3 font-display text-lg text-muted" aria-hidden="true">$</span>
        <input id="{{ $prefix }}amount" name="amount" type="text" inputmode="decimal" autocomplete="off" @if ($menu->isEmpty()) required @endif
               value="{{ $amount }}" placeholder="0.00" data-basket-other
               class="tabular w-full rounded-xl bg-transparent px-2 py-3 text-lg text-ink focus:outline-none"
               aria-describedby="{{ $prefix }}amount-hint @if ($amountError) {{ $prefix }}amount-error @endif"
               @if ($amountError) aria-invalid="true" @endif>
    </div>
    <p id="{{ $prefix }}amount-hint" class="mt-1.5 text-sm text-muted">{{ $menu->isNotEmpty() ? 'For anything not on the menu. In dollars, for example 12.50' : 'In dollars, for example 12.50' }}</p>
    @if ($amountError) <p id="{{ $prefix }}amount-error" class="mt-1.5 text-sm font-semibold text-hold">{{ $amountError }}</p> @endif
</div>

<p hidden data-basket-summary class="flex items-baseline justify-between rounded-xl bg-primary-tint px-4 py-3 text-primary" aria-live="polite">
    <span class="font-semibold">Total</span>
    <span class="tabular font-display text-2xl font-semibold" data-basket-total>$0.00</span>
</p>
<noscript><p class="text-sm text-muted">The total is worked out from the menu prices when you confirm.</p></noscript>
