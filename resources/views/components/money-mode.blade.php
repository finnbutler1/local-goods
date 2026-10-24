{{-- Which money mode a page or a record is in: "Stripe test mode" or "Simulated" (ADR 0015). Never shows one as the other. --}}
@props(['mode' => null, 'id' => null])

@php
    $mode ??= \App\Support\StripeMode::current();
    $stripe = $mode === \App\Support\StripeMode::STRIPE;
@endphp

<span {{ $attributes->class(['inline-flex flex-wrap items-center gap-1.5']) }}>
    <x-badge :tone="$stripe ? 'primary' : 'neutral'">
        <span aria-hidden="true">{{ $stripe ? '◆' : '◇' }}</span>
        {{ $stripe ? 'Stripe test mode' : 'Simulated' }}
        <span class="sr-only">{{ $stripe ? ': test money through Stripe, no real money' : ': no money moves, not sent to Stripe' }}</span>
    </x-badge>
    @if ($id)
        <code class="wrap-anywhere-safe font-mono text-xs text-muted">{{ $id }}</code>
    @endif
</span>
