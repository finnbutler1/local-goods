{{-- "Request a correction" on one item (ADR 0028), plus the status and the
     admin's reason of the newest request about it. Asking moves no money.
     $for is the item's key ("redemption:42"): old input with the same key
     reopens this form with its errors, so other forms on the page stay shut.
     Judges see the form disabled, read-only. --}}
@php
    /** @var ?\App\Models\Correction $correction */
    $readOnly = \Illuminate\Support\Facades\Gate::denies('request-corrections');
    $mine = old('correction_for') === $for;
    $errs = $errors->getBag(\App\Http\Controllers\CorrectionRequestController::ERRORS);
    $prefix = 'corr-'.str_replace(':', '-', $for);
    $input = 'block w-full rounded-xl border-2 bg-surface px-3 py-2.5 focus-visible:border-primary';
    $tone = match ($correction?->status) {
        \App\Models\Correction::STATUS_COMPLETED => 'ok',
        \App\Models\Correction::STATUS_FAILED => 'hold',
        default => 'neutral',
    };
@endphp

<div class="mt-2 space-y-2 text-sm" data-correction="{{ $for }}">
    @if ($correction)
        <div data-correction-status="{{ $correction->status }}">
            <x-badge :tone="$tone" :wrap="true">{{ $correction->typeLabel() }}: {{ $correction->statusLabel() }}@if ($correction->status === \App\Models\Correction::STATUS_COMPLETED && $correction->amount_cents) · {{ Money::format($correction->amount_cents) }}@endif</x-badge>
            @if ($correction->decision_reason)
                <p class="mt-1 text-muted">Local Goods: “{{ $correction->decision_reason }}”</p>
            @elseif ($correction->status === \App\Models\Correction::STATUS_REQUESTED)
                <p class="mt-1 text-muted">Asked {{ $correction->created_at->setTimezone('America/New_York')->format('M j') }}. A Local Goods admin will decide.</p>
            @endif
            @if ($correction->status === \App\Models\Correction::STATUS_FAILED)
                <p class="mt-1 text-muted">Stripe didn't complete it yet. A Local Goods admin will retry it.</p>
            @endif
        </div>
    @endif

    @if ($canAsk && ! $correction?->isOpen())
        <details @if ($mine) open @endif>
            <summary class="tap-target cursor-pointer font-semibold text-primary">{{ $summary }}</summary>
            {{-- Judges get the same fields, disabled, with no form around them: nothing to send. --}}
            @if ($readOnly)
            <div class="mt-2 space-y-3">
            @else
            <form method="POST" action="{{ $action }}" data-busy="Sending…" class="mt-2 space-y-3">
                @csrf
                <input type="hidden" name="correction_for" value="{{ $for }}">
            @endif
                <fieldset @disabled($readOnly) class="space-y-3">
                    <x-field name="reason" :id="$prefix.'-reason'" label="What went wrong?" :error="$mine ? ($errs->first('reason') ?: null) : null">
                        <select id="{{ $prefix }}-reason" name="reason" required class="{{ $input }} {{ $mine && $errs->has('reason') ? 'border-hold' : 'border-muted' }}"
                                @if ($mine && $errs->has('reason')) aria-invalid="true" aria-describedby="{{ $prefix }}-reason-error" @endif>
                            <option value="">Choose one</option>
                            @foreach (\App\Support\CorrectionReasons::for($context) as $key => $label)
                                <option value="{{ $key }}" @selected($mine && old('reason') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field name="note" :id="$prefix.'-note'" label="Note (optional)" hint="Don't include names or card details." :error="$mine ? ($errs->first('note') ?: null) : null">
                        <textarea id="{{ $prefix }}-note" name="note" rows="2" maxlength="500" class="{{ $input }} {{ $mine && $errs->has('note') ? 'border-hold' : 'border-muted' }}"
                                  aria-describedby="{{ $prefix }}-note-hint{{ $mine && $errs->has('note') ? ' '.$prefix.'-note-error' : '' }}"
                                  @if ($mine && $errs->has('note')) aria-invalid="true" @endif>{{ $mine ? old('note') : '' }}</textarea>
                    </x-field>
                    <x-button type="submit" size="sm" :aria-describedby="$readOnly ? $prefix.'-read-only' : null">Send request</x-button>
                </fieldset>
            @if ($readOnly)
                <p id="{{ $prefix }}-read-only" class="text-muted">Read-only: judges can't send requests.</p>
            </div>
            @else
            </form>
            @endif
        </details>
    @endif
</div>
