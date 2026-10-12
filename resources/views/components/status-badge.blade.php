@props(['redemption'])

@if ($redemption->isApproved())
    <x-badge tone="ok" {{ $attributes }}><span class="size-1.5 rounded-full bg-ok" aria-hidden="true"></span>Approved</x-badge>
@else
    <x-badge tone="hold" {{ $attributes }}><span class="size-1.5 rounded-full bg-hold" aria-hidden="true"></span>Under review</x-badge>
@endif
