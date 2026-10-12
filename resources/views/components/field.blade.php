@props(['name', 'label', 'id' => null, 'hint' => null, 'error' => null])

{{-- Label, hint and error around a form control passed in the slot. The control
     should use id="{id}" and aria-describedby="{id}-hint {id}-error" as needed. --}}
@php
    $id ??= $name;
    $error ??= $errors->first($name) ?: null;
    $describedBy = trim(($hint ? "{$id}-hint " : '').($error ? "{$id}-error" : ''));
@endphp

<div {{ $attributes->class('space-y-1.5') }}>
    <label for="{{ $id }}" class="block font-semibold text-ink">{{ $label }}</label>
    {{ $slot }}
    @if ($hint)
        <p id="{{ $id }}-hint" class="text-sm text-muted">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" class="flex items-center gap-1.5 text-sm font-semibold text-hold">
            <span aria-hidden="true">⚑</span>{{ $error }}
        </p>
    @endif
</div>
