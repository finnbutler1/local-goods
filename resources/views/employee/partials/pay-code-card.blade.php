{{--
    The pay code screen (ADR 0026). Big enough to read across a counter at 375px. Screen readers get the
    code one character at a time and a fixed expiry time; the ticking countdown is hidden from them, and a
    polite live region speaks only at one minute left and when it expires. Polls for the merchant's answer.
--}}
@php use App\Support\Money; $expires = $expiresAt->setTimezone('America/New_York'); @endphp
<div @class(['mt-5 rounded-3xl border-2 p-5 text-center sm:p-6', 'border-primary bg-primary-tint' => ! $sample, 'border-dashed border-line' => $sample])
     @if ($statusUrl) data-pay-code data-pay-code-status-url="{{ $statusUrl }}" data-pay-code-result-url="{{ $resultUrl }}" @endif
     data-expires-at="{{ $expiresAt->toIso8601String() }}">
    <p class="font-semibold text-ink">Show this code to the cashier at {{ $merchantName }}</p>
    <p class="mt-3 whitespace-nowrap font-display text-[clamp(2.75rem,15vw,4.5rem)] font-bold leading-none tracking-[0.12em] text-ink" aria-hidden="true">{{ $code }}</p>
    <p class="sr-only">{{ $sample ? 'Sample pay code' : 'Pay code' }}: {{ $spoken }}</p>
    <p class="tabular mt-4 font-display text-3xl font-semibold text-ink">{{ Money::format($amountCents) }}</p>
    @if ($items) <p class="mt-1 text-ink" data-items>{{ $items }}</p> @endif
    @include('partials.tax-breakdown', ['tax' => $tax, 'class' => 'mx-auto mt-3 max-w-xs text-left text-sm text-ink'])
    <p class="mt-3 text-sm text-ink">
        <span aria-hidden="true">Expires in <span class="tabular font-semibold" data-countdown>{{ $sample ? '10:00' : sprintf('%d:%02d', intdiv($left = max(0, (int) now()->diffInSeconds($expiresAt, false)), 60), $left % 60) }}</span> · at {{ $expires->format('g:i A') }}</span>
        <span class="sr-only">Expires at {{ $expires->format('g:i A') }}</span>
    </p>
    <p class="sr-only" aria-live="polite" data-countdown-announce></p>

    @if ($sample)
        <p class="mt-3 text-sm text-muted">Sample only: judges can't get pay codes. The cashier types the code in on their Local Goods dashboard.</p>
    @else
        <p class="mt-3 flex items-center justify-center gap-2 text-sm text-ink" role="status">
            <svg viewBox="0 0 24 24" fill="none" class="size-4 motion-safe:animate-spin" aria-hidden="true" data-pay-code-spinner><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            <span data-pay-code-waiting>Waiting for {{ $merchantName }} to confirm…</span>
        </p>
        <noscript><p class="mt-2 text-sm text-muted">Refresh this page to see when it's confirmed.</p></noscript>
        <form method="POST" action="{{ $cancelUrl }}" data-busy="Cancelling…" class="mt-4">
            @csrf
            <x-button type="submit" variant="secondary" class="w-full bg-surface">Cancel</x-button>
        </form>
    @endif
</div>
