@props(['redemption'])

@if ($redemption->isApproved())
    <span {{ $attributes->class('inline-flex items-center rounded-full bg-ok-bg px-2.5 py-0.5 text-xs font-semibold text-ok') }}>Approved</span>
@else
    <span {{ $attributes->class('inline-flex items-center rounded-full bg-hold-bg px-2.5 py-0.5 text-xs font-semibold text-hold') }}>Under review</span>
@endif
