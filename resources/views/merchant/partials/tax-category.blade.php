{{-- A product's DC sales tax category (ADR 0027), with today's rate from config. A starting point only. --}}
@php
    use App\Support\SalesTax;
    use App\Support\TaxBreakdown;
    $error = $bag->first('tax_category') ?: null;
@endphp
<x-field name="tax_category" :id="$id" label="Tax category" hint="Sets the DC tax added at checkout. Confirm this with your accountant." :error="$error">
    <select id="{{ $id }}" name="tax_category" aria-describedby="{{ $id }}-hint @if ($error) {{ $id }}-error @endif" @if ($error) aria-invalid="true" @endif
            class="block w-full rounded-xl border-2 border-muted bg-surface px-3 py-2.5 focus-visible:border-primary">
        @foreach (SalesTax::categories() as $category)
            @php $rate = SalesTax::rateBps($category); @endphp
            <option value="{{ $category }}" @selected($selected === $category)>
                {{ SalesTax::name($category) }}{{ $rate === 0 ? ' ('.rtrim(lcfirst(config("tax.categories.{$category}.description")), '.').')' : ' ('.TaxBreakdown::lineLabel(SalesTax::label($category), $rate).')' }}
            </option>
        @endforeach
    </select>
</x-field>
