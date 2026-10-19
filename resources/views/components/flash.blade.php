@props(['status' => session('status'), 'error' => session('error'), 'focus' => true])

{{-- The result of the last action ("Saved", "Nothing changed: …"). After the
     page reloads, resources/js/app.js moves focus here (data-flash) so screen
     readers announce it and keyboard users continue from it. --}}
@if ($status)
    <p role="status" @if ($focus) tabindex="-1" data-flash @endif {{ $attributes->class('rounded-2xl border-2 border-ok bg-ok-bg p-4 font-semibold text-ok') }}>{{ $status }}</p>
@endif
@if ($error)
    <p role="alert" @if ($focus) tabindex="-1" data-flash @endif {{ $attributes->class('rounded-2xl border-2 border-hold bg-hold-bg p-4 font-semibold text-hold') }}>{{ $error }}</p>
@endif
