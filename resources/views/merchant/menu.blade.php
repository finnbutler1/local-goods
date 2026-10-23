@php use App\Support\Money; @endphp

<x-app-shell title="Menu" active="menu" :context="$merchant->name.' · Golden Triangle BID'">
<div class="mx-auto max-w-2xl space-y-8">
    <header>
        <h1 class="text-3xl font-semibold text-primary">Your menu</h1>
        <p class="mt-2 text-muted">
            Employees pick from this menu when they pay with Local Goods credits, and can add an other amount for anything not on it.
            A change applies to new redemptions only: past ones keep the name and price they had.
        </p>
        <p class="mt-2 text-sm text-muted">
            The menu is display only. Redemption Fraud Detection checks the amount, never the items.
            Reset demo puts the original menu back.
        </p>
        @if ($readOnly)
            <p class="mt-3 rounded-xl bg-primary-tint p-3 text-sm text-primary">Read-only: judges can see {{ $merchant->name }}'s menu but can't change it.</p>
        @endif
    </header>

    @if ($products->isEmpty())
        <x-card class="text-muted">No products on this menu. Employees enter an amount when they redeem here.</x-card>
    @else
        <ul class="space-y-4" aria-label="Products">
            @foreach ($products as $product)
                @php
                    $bag = $errors->getBag('product'.$product->id);
                    $id = 'product-'.$product->id;
                    $value = fn (string $field, $fallback) => $bag->any() ? old($field, $fallback) : $fallback;
                @endphp
                <x-card as="li" id="{{ $id }}" padding="p-5" @class(['scroll-mt-24', 'opacity-75' => ! $product->is_available])>
                    <form method="POST" action="{{ route('merchant.menu.update', $product) }}" data-busy="Saving…" class="space-y-4">
                        @csrf
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 class="text-lg font-semibold">{{ $product->name }} <span class="tabular font-normal text-muted">· {{ Money::format($product->price_cents) }}</span></h2>
                            @unless ($product->is_available)
                                <x-badge tone="neutral">Off the menu</x-badge>
                            @endunless
                        </div>
                        <div class="grid gap-4 sm:grid-cols-[1fr_9rem]">
                            <x-field name="name" :id="$id.'-name'" label="Name" :error="$bag->first('name') ?: null">
                                <input id="{{ $id }}-name" name="name" type="text" required maxlength="80" value="{{ $value('name', $product->name) }}"
                                       class="block w-full rounded-xl border-2 border-muted bg-surface px-3 py-2.5 focus-visible:border-primary"
                                       @if ($bag->has('name')) aria-invalid="true" aria-describedby="{{ $id }}-name-error" @endif>
                            </x-field>
                            <x-field name="price" :id="$id.'-price'" label="Price ($)" :error="$bag->first('price') ?: null">
                                <input id="{{ $id }}-price" name="price" type="text" inputmode="decimal" required value="{{ $value('price', Money::toDollars($product->price_cents)) }}"
                                       class="tabular block w-full rounded-xl border-2 border-muted bg-surface px-3 py-2.5 focus-visible:border-primary"
                                       @if ($bag->has('price')) aria-invalid="true" aria-describedby="{{ $id }}-price-error" @endif>
                            </x-field>
                        </div>
                        <x-field name="description" :id="$id.'-description'" label="Short description (optional)" :error="$bag->first('description') ?: null">
                            <input id="{{ $id }}-description" name="description" type="text" maxlength="160" value="{{ $value('description', $product->description) }}"
                                   class="block w-full rounded-xl border-2 border-muted bg-surface px-3 py-2.5 focus-visible:border-primary"
                                   @if ($bag->has('description')) aria-invalid="true" aria-describedby="{{ $id }}-description-error" @endif>
                        </x-field>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_available" value="1" @checked($product->is_available) class="size-5 accent-primary">
                            On the menu (employees can pick it)
                        </label>
                        <x-button type="submit" size="sm" :disabled="$readOnly">Save {{ $product->name }}</x-button>
                    </form>
                </x-card>
            @endforeach
        </ul>
    @endif

    @if ($canAdd)
        @php $add = $errors->getBag('add'); @endphp
        <x-card as="section" id="add" aria-labelledby="add-heading" padding="p-5" class="scroll-mt-24">
            <h2 id="add-heading" class="text-xl font-semibold text-primary">Add a product</h2>
            <form method="POST" action="{{ route('merchant.menu.store') }}" data-busy="Adding…" class="mt-4 space-y-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-[1fr_9rem]">
                    <x-field name="name" id="add-name" label="Name" :error="$add->first('name') ?: null">
                        <input id="add-name" name="name" type="text" required maxlength="80" value="{{ $add->any() ? old('name') : '' }}"
                               class="block w-full rounded-xl border-2 border-muted bg-surface px-3 py-2.5 focus-visible:border-primary"
                               @if ($add->has('name')) aria-invalid="true" aria-describedby="add-name-error" @endif>
                    </x-field>
                    <x-field name="price" id="add-price" label="Price ($)" :error="$add->first('price') ?: null">
                        <input id="add-price" name="price" type="text" inputmode="decimal" required value="{{ $add->any() ? old('price') : '' }}" placeholder="0.00"
                               class="tabular block w-full rounded-xl border-2 border-muted bg-surface px-3 py-2.5 focus-visible:border-primary"
                               @if ($add->has('price')) aria-invalid="true" aria-describedby="add-price-error" @endif>
                    </x-field>
                </div>
                <x-field name="description" id="add-description" label="Short description (optional)" :error="$add->first('description') ?: null">
                    <input id="add-description" name="description" type="text" maxlength="160" value="{{ $add->any() ? old('description') : '' }}"
                           class="block w-full rounded-xl border-2 border-muted bg-surface px-3 py-2.5 focus-visible:border-primary"
                           @if ($add->has('description')) aria-invalid="true" aria-describedby="add-description-error" @endif>
                </x-field>
                <x-button type="submit" size="sm" :disabled="$readOnly">Add to the menu</x-button>
            </form>
        </x-card>
    @endif

    <p><a href="{{ route('merchant.dashboard') }}" class="font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Back to the merchant dashboard</a></p>
</div>
</x-app-shell>
