@php use App\Support\Money; @endphp
@extends('layouts.app')

@section('title', 'Employee wallet · Local Goods')

@section('content')
<div class="mx-auto max-w-md px-4 py-8 space-y-6">

    <p class="text-sm text-muted">Demo employee: <span class="font-semibold text-forest">{{ $employee->display_name }}</span>, {{ $employee->employer->name }}</p>

    {{-- Result of the last redemption, announced to screen readers --}}
    <div aria-live="polite">
        @if ($result)
            @if ($result->isApproved())
                <div class="rounded-xl border-2 border-ok bg-ok-bg p-4 text-ok">
                    <p class="text-lg font-semibold">Approved</p>
                    <p class="mt-1">{{ Money::format($result->amount_cents) }} at {{ $result->merchant->name }}. Show this screen at the counter.</p>
                </div>
            @else
                <div class="rounded-xl border-2 border-hold bg-hold-bg p-4 text-hold">
                    <p class="text-lg font-semibold">Under review</p>
                    <p class="mt-1">{{ Money::format($result->amount_cents) }} at {{ $result->merchant->name }} needs a quick check by a person before the merchant is paid. Your credits are reserved in the meantime.</p>
                </div>
            @endif
        @endif
    </div>

    <section aria-labelledby="balance-heading" class="rounded-2xl bg-forest p-6 text-cream shadow-sm">
        <h1 id="balance-heading" class="text-sm font-semibold uppercase tracking-widest">Available balance</h1>
        <p class="mt-2 text-5xl font-semibold tracking-tight" data-testid="balance">{{ Money::format($balance) }}</p>
        <p class="mt-3 text-sm">{{ Money::format($employee->employer->monthly_allowance_cents) }} added each month by {{ $employee->employer->name }}</p>
    </section>

    <section id="redeem" aria-labelledby="redeem-heading" class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-cream-deep">
        <h2 id="redeem-heading" class="text-xl font-semibold">Redeem credits</h2>

        @if ($suspicious)
            <p class="mt-2 rounded-lg bg-hold-bg p-3 text-sm text-hold">
                Pre-filled with a suspicious pattern: the whole balance, as a round number. Confirm it to watch Redemption Fraud Detection hold it.
            </p>
        @endif

        <form method="POST" action="{{ route('demo.employee.redeem') }}" class="mt-4 space-y-4">
            @csrf
            <div>
                <label for="merchant_id" class="block font-medium">Merchant</label>
                <select id="merchant_id" name="merchant_id" required
                        class="mt-1 block w-full rounded-lg border border-muted bg-white px-3 py-2.5 text-forest"
                        @error('merchant_id') aria-invalid="true" aria-describedby="merchant-error" @enderror>
                    <option value="">Choose a merchant</option>
                    @foreach ($merchants as $merchant)
                        <option value="{{ $merchant->id }}" @selected((string) old('merchant_id', $prefill['merchant_id']) === (string) $merchant->id)>
                            {{ $merchant->name }} ({{ $merchant->category }})
                        </option>
                    @endforeach
                </select>
                @error('merchant_id') <p id="merchant-error" class="mt-1 text-sm text-hold">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="amount" class="block font-medium">Amount</label>
                <div class="mt-1 flex items-center rounded-lg border border-muted bg-white focus-within:outline-3 focus-within:outline-amber">
                    <span class="pl-3 text-muted" aria-hidden="true">$</span>
                    <input id="amount" name="amount" type="text" inputmode="decimal" autocomplete="off" required
                           value="{{ old('amount', $prefill['amount']) }}" placeholder="0.00"
                           class="w-full rounded-lg bg-transparent px-2 py-2.5 text-forest focus:outline-none"
                           aria-describedby="amount-hint @error('amount') amount-error @enderror"
                           @error('amount') aria-invalid="true" @enderror>
                </div>
                <p id="amount-hint" class="mt-1 text-sm text-muted">In dollars, for example 12.50</p>
                @error('amount') <p id="amount-error" class="mt-1 text-sm text-hold">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="w-full rounded-lg bg-amber px-5 py-3 font-semibold text-forest shadow-sm hover:brightness-95">
                Confirm redemption
            </button>
        </form>

        <a href="{{ route('demo.employee', ['suspicious' => 1]) }}#redeem"
           class="mt-3 inline-flex w-full justify-center rounded-lg border-2 border-hold px-5 py-2.5 text-sm font-semibold text-hold hover:bg-hold-bg">
            Try a suspicious redemption
        </a>
    </section>

    <section aria-labelledby="recent-heading">
        <h2 id="recent-heading" class="text-xl font-semibold">Recent redemptions</h2>
        <ul class="mt-3 divide-y divide-cream-deep rounded-2xl bg-white shadow-sm ring-1 ring-cream-deep">
            @forelse ($recent as $redemption)
                <li class="flex items-center justify-between gap-3 p-4">
                    <div>
                        <p class="font-medium">{{ $redemption->merchant->name }}</p>
                        <p class="text-sm text-muted">{{ $redemption->created_at->setTimezone('America/New_York')->format('M j, g:i A') }}</p>
                    </div>
                    <div class="text-right">
                        <p class="font-semibold">{{ Money::format($redemption->amount_cents) }}</p>
                        <x-status-badge :redemption="$redemption" class="mt-1" />
                    </div>
                </li>
            @empty
                <li class="p-4 text-muted">No redemptions yet.</li>
            @endforelse
        </ul>
    </section>
</div>
@endsection
