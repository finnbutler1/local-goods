@props(['redemption'])

@if ($redemption->isApproved())
    <x-badge tone="ok" {{ $attributes }}><span class="size-1.5 rounded-full bg-ok" aria-hidden="true"></span>Approved</x-badge>
@elseif ($redemption->isCancelled())
    <x-badge tone="neutral" {{ $attributes }}><span class="size-1.5 rounded-full bg-ink" aria-hidden="true"></span>{{ $redemption->cancelledLabel() }}</x-badge>
@elseif ($redemption->isRejected())
    <x-badge tone="neutral" {{ $attributes }}><span class="size-1.5 rounded-full bg-ink" aria-hidden="true"></span>Denied</x-badge>
@else
    <x-badge tone="hold" {{ $attributes }}><span class="size-1.5 rounded-full bg-hold" aria-hidden="true"></span>Under review</x-badge>
@endif
