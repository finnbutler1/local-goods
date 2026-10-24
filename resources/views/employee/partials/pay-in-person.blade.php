{{-- Pay in person (ADR 0026): pick a merchant, the amount or menu items, then get a one-time code for the cashier. --}}
@php use App\Models\PayCode; use App\Support\Money; @endphp
<x-card as="section" id="pay" aria-labelledby="pay-heading" class="scroll-mt-24">
    <h2 id="pay-heading" class="text-2xl font-semibold text-primary">Pay in person</h2>
    <p class="mt-1 text-sm text-muted">At the counter: get a pay code, the cashier types it in and confirms. Nothing is charged until they do.</p>

    @if ($liveCode)
        @include('employee.partials.pay-code-card', [
            'code' => $liveCode->displayCode(),
            'spoken' => $liveCode->spokenCode(),
            'merchantName' => $liveCode->merchant->name,
            'amountCents' => $liveCode->amount_cents,
            'items' => $liveCode->itemsSummary(),
            'expiresAt' => $liveCode->expires_at,
            'statusUrl' => route('employee.pay-code.status', $liveCode),
            'resultUrl' => route('employee.wallet', ['pay_code' => $liveCode->id]).'#wallet-tabs',
            'cancelUrl' => route('employee.pay-code.cancel', $liveCode),
            'sample' => false,
        ])
    @else
        @if ($payResult && ! $payResult->redemption)
            @php
                $outcome = match (true) {
                    $payResult->currentStatus() === PayCode::STATUS_EXPIRED => 'That pay code expired before it was used.',
                    $payResult->cancelled_by === PayCode::BY_MERCHANT => $payResult->merchant->name.' declined that pay code.',
                    $payResult->cancelled_by === PayCode::BY_BALANCE => 'There weren\'t enough credits for that payment.',
                    $payResult->status === PayCode::STATUS_USED => 'That pay code is being confirmed.',
                    default => 'That pay code was cancelled.',
                };
            @endphp
            <p class="mt-4 rounded-xl border-2 border-line p-3" data-pay-outcome>
                {{ $outcome.($payResult->status !== PayCode::STATUS_USED ? ' Nothing was charged.' : '') }}
            </p>
        @endif

        @if ($payMerchant === null)
            {{-- Step 1: where you're paying. --}}
            <form method="GET" action="{{ route('employee.wallet') }}#pay" class="mt-5 space-y-4">
                <input type="hidden" name="tab" value="in-person">
                <div>
                    <label for="pay-merchant" class="block font-semibold">Where are you paying?</label>
                    <select id="pay-merchant" name="pay_merchant" required
                            class="mt-1.5 block w-full rounded-xl border-2 border-muted bg-surface px-3 py-3 text-ink focus-visible:border-primary"
                            @if ($payErrors->has('pay_merchant_id')) aria-invalid="true" aria-describedby="pay-merchant-error" @endif>
                        <option value="">Choose a merchant</option>
                        @foreach ($merchants as $merchant)
                            <option value="{{ $merchant->id }}">{{ $merchant->name }} ({{ $merchant->category }})</option>
                        @endforeach
                    </select>
                    @if ($payErrors->has('pay_merchant_id')) <p id="pay-merchant-error" class="mt-1.5 text-sm font-semibold text-hold">{{ $payErrors->first('pay_merchant_id') }}</p> @endif
                </div>
                <x-button type="submit" size="lg" class="w-full">Next</x-button>
            </form>
        @else
            {{-- Step 2: how much. The code is for exactly this amount, at this merchant only. --}}
            <form method="POST" action="{{ route('employee.pay-code.store') }}" data-busy="Getting your pay code…" data-basket class="mt-5 space-y-5">
                @csrf
                <input type="hidden" name="pay_merchant_id" value="{{ $payMerchant->id }}">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <p class="text-lg font-semibold">Paying {{ $payMerchant->name }}</p>
                    <a href="{{ route('employee.wallet', ['tab' => 'in-person']) }}#pay" class="text-sm font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Change merchant</a>
                </div>

                @include('employee.partials.basket-fields', [
                    'prefix' => 'pay-',
                    'menu' => $payMenu,
                    'quantities' => old('pay_merchant_id') ? old('items', []) : [],
                    'amount' => old('pay_merchant_id') ? old('amount') : null,
                    'errors' => $payErrors,
                ])

                @can('redeem')
                    <x-button type="submit" variant="accent" size="lg" class="w-full">Get pay code</x-button>
                @else
                    <x-button type="submit" variant="accent" size="lg" class="w-full" disabled aria-describedby="pay-read-only-note">Get pay code</x-button>
                    <p id="pay-read-only-note" class="text-sm text-muted">Read-only: judges can't get pay codes.</p>
                @endcan
            </form>
        @endif

        @cannot('redeem')
            {{-- Judges: what the code screen looks like. Not a real code; nothing is stored. --}}
            <div class="mt-6">
                <p class="text-sm font-semibold uppercase tracking-widest text-muted">Sample code screen</p>
                @include('employee.partials.pay-code-card', [
                    'code' => 'K7M 4PQ',
                    'spoken' => 'K, 7, M, 4, P, Q',
                    'merchantName' => 'Farragut Grind',
                    'amountCents' => 850,
                    'items' => '2× Latte',
                    'expiresAt' => now()->addMinutes(PayCode::EXPIRES_MINUTES),
                    'statusUrl' => null,
                    'resultUrl' => null,
                    'cancelUrl' => null,
                    'sample' => true,
                ])
            </div>
        @endcannot
    @endif
</x-card>
