{{-- What is waiting for a person to decide (ADR 0032). Nothing is decided automatically. --}}
@php
    $waiting = \App\Support\WaitingForAPerson::summary();
    $age = fn ($since) => \App\Support\WaitingForAPerson::age($since);
@endphp
<section aria-labelledby="waiting-heading" @class(['rounded-2xl border-2 p-4', 'border-hold bg-hold-bg' => $waiting['overdue'], 'border-line bg-surface' => ! $waiting['overdue']]) data-waiting="{{ $waiting['total'] }}">
    <h2 id="waiting-heading" class="font-semibold">
        Waiting for a person: {{ $waiting['total'] === 0 ? 'nothing' : $waiting['total'] }}
    </h2>
    @if ($waiting['total'] === 0)
        <p class="mt-1 text-sm text-muted">No held payments and no refund requests are waiting for a decision.</p>
    @else
        <ul class="mt-2 space-y-1 text-sm">
            @if ($waiting['held'] > 0)
                <li>
                    <a href="{{ route('bid-admin.dashboard') }}#recent-heading" class="tap-target font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">{{ $waiting['held'] }} held {{ Str::plural('payment', $waiting['held']) }}</a>,
                    the oldest waiting {{ $age($waiting['held_oldest']) }}
                </li>
            @endif
            @if ($waiting['refunds'] > 0)
                <li>
                    <a href="{{ route('bid-admin.corrections') }}#open-heading" class="tap-target font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">{{ $waiting['refunds'] }} refund {{ Str::plural('request', $waiting['refunds']) }}</a>,
                    the oldest waiting {{ $age($waiting['refunds_oldest']) }}
                </li>
            @endif
        </ul>
        @if ($waiting['overdue'])
            <p class="mt-2 text-sm font-semibold" data-waiting-overdue>Something has waited more than {{ $waiting['overdue_days'] }} days for a person. Nothing is decided automatically: it stays here until someone decides it.</p>
        @else
            <p class="mt-2 text-sm text-muted">A BID or platform admin decides each held payment; the platform admin decides refunds. Nothing is decided automatically.</p>
        @endif
    @endif
</section>
