@props(['prefix', 'bag', 'feedback' => null, 'pageUrl'])

{{-- The fields of a feedback form, shared by the Feedback button and the edit
     form on /account. $prefix keeps ids unique when several forms are on a page. --}}
@php
    $errs = $errors->getBag($bag);
    $value = fn (string $field) => $errs->any() ? old($field) : ($feedback?->{$field} instanceof \BackedEnum ? $feedback->{$field}->value : $feedback?->{$field});
    $input = 'block w-full rounded-xl border-2 bg-paper px-3 py-2.5 focus-visible:border-forest';
@endphp

<input type="hidden" name="page_url" value="{{ $feedback?->page_url ?? $pageUrl }}">

<x-field name="category" :id="$prefix.'-category'" label="Rubric category" :error="$errs->first('category') ?: null">
    <select id="{{ $prefix }}-category" name="category" required @if ($errs->has('category')) aria-invalid="true" aria-describedby="{{ $prefix }}-category-error" @endif class="{{ $input }} {{ $errs->has('category') ? 'border-hold' : 'border-muted' }}">
        <option value="">Choose one</option>
        @foreach (\App\Enums\FeedbackCategory::cases() as $category)
            <option value="{{ $category->value }}" @selected($value('category') === $category->value)>{{ $category->label() }}</option>
        @endforeach
    </select>
</x-field>

<fieldset class="space-y-1.5" @if ($errs->has('rating')) role="radiogroup" aria-invalid="true" aria-describedby="{{ $prefix }}-rating-error" @endif>
    <legend class="font-semibold">Rating <span class="font-normal text-muted">(1 = poor, 5 = excellent)</span></legend>
    <div class="flex gap-2">
        @foreach (range(1, 5) as $n)
            <label class="cursor-pointer">
                <input type="radio" name="rating" value="{{ $n }}" required class="peer sr-only" @checked((string) $value('rating') === (string) $n)>
                <span class="grid size-10 place-items-center rounded-full border-2 border-muted font-semibold peer-checked:border-forest peer-checked:bg-forest peer-checked:text-cream peer-focus-visible:outline-3 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-amber">{{ $n }}</span>
            </label>
        @endforeach
    </div>
    @if ($errs->has('rating'))
        <p id="{{ $prefix }}-rating-error" class="text-sm font-semibold text-hold"><span aria-hidden="true">⚑</span> {{ $errs->first('rating') }}</p>
    @endif
</fieldset>

<x-field name="severity" :id="$prefix.'-severity'" label="How important is it?" :error="$errs->first('severity') ?: null">
    <select id="{{ $prefix }}-severity" name="severity" required @if ($errs->has('severity')) aria-invalid="true" aria-describedby="{{ $prefix }}-severity-error" @endif class="{{ $input }} {{ $errs->has('severity') ? 'border-hold' : 'border-muted' }}">
        @foreach (\App\Enums\FeedbackSeverity::cases() as $severity)
            <option value="{{ $severity->value }}" @selected(($value('severity') ?? 'should_fix') === $severity->value)>{{ $severity->label() }}</option>
        @endforeach
    </select>
</x-field>

<x-field name="comment" :id="$prefix.'-comment'" label="Comment" :error="$errs->first('comment') ?: null">
    <textarea id="{{ $prefix }}-comment" name="comment" rows="4" required @if ($errs->has('comment')) aria-invalid="true" aria-describedby="{{ $prefix }}-comment-error" @endif maxlength="5000"
              class="{{ $input }} {{ $errs->has('comment') ? 'border-hold' : 'border-muted' }}">{{ $value('comment') }}</textarea>
</x-field>
