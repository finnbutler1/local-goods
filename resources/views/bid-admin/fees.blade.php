@php
    use App\Models\User;
    use App\Support\MerchantFee;
    use App\Support\Money;

    $input = 'block w-full rounded-xl border-2 border-muted bg-surface px-3 py-2 focus-visible:border-primary';
    $figures = MerchantFee::showsFiguresTo(auth()->user());
    $show = fn (int $bps, int $fixed) => \App\Http\Controllers\BidAdmin\MerchantFeeController::short(new MerchantFee($bps, $fixed));
@endphp

<x-app-shell title="Fee settings" active="fees" context="Golden Triangle BID · Washington, DC">
<div class="space-y-10">
    <header class="max-w-3xl">
        <x-eyebrow class="text-accent-ink">Fee settings</x-eyebrow>
        <h1 class="mt-1 text-title font-semibold text-primary">Local Goods fee on merchant sales</h1>
        <p class="mt-3 text-lg text-muted">
            How Local Goods would earn money: a fee on each cleared sale, kept out of what the merchant is transferred.
            It is set to charge nothing until we have talked with merchants about it.
        </p>
        <ul class="mt-4 list-disc space-y-1 pl-5 text-muted">
            @if ($figures)
                <li>A percent of the sale (tax included) plus an optional fixed amount, at most {{ MerchantFee::percent(MerchantFee::MAX_BPS) }} plus $1.00, and never more than the sale.</li>
            @else
                <li>A percent of the sale (tax included) plus an optional fixed amount, both capped, and never more than the sale.</li>
            @endif
            <li>Each redemption keeps the fee it was made with. A change applies to new redemptions only.</li>
            <li>A refund gives back the same share of the fee. Redemption Fraud Detection never sees the fee.</li>
        </ul>
        @unless ($change)
            <p id="fees-read-only" class="mt-4 rounded-2xl bg-band p-4 text-sm"><span class="font-semibold">Read-only.</span> Only the Local Goods platform admin changes the fee.</p>
        @endunless
    </header>

    <x-card as="section" aria-labelledby="current-heading">
        <h2 id="current-heading" class="text-xl font-semibold text-primary">Current fee</h2>
        <p class="mt-2 text-3xl font-semibold text-ink" data-current-fee>{{ ucfirst($figures ? $current->label() : $current->wordsLabel()) }}</p>
        @if ($figures)
            <p class="mt-1 text-sm text-muted">
                On a {{ Money::format($exampleCents) }} sale: Local Goods fee {{ Money::format($current->centsOn($exampleCents)) }},
                the merchant receives {{ Money::format($exampleCents - $current->centsOn($exampleCents)) }}.
            </p>
        @else
            <p class="mt-1 text-sm text-muted">Judges see this page without amounts, like every public page.</p>
        @endif
    </x-card>

    @if ($change)
        @if ($review)
            @php($new = $review['fee'])
            <x-card as="section" aria-labelledby="review-heading" data-fee-review>
                <h2 id="review-heading" class="text-xl font-semibold text-primary">Check the change</h2>
                <dl class="mt-3 grid gap-2 sm:grid-cols-[max-content_1fr] sm:gap-x-6">
                    <dt class="text-muted">Now</dt><dd class="font-semibold">{{ ucfirst($current->label()) }}</dd>
                    <dt class="text-muted">New</dt><dd class="font-semibold">{{ ucfirst($new->label()) }}</dd>
                    <dt class="text-muted">On a {{ Money::format($exampleCents) }} sale</dt>
                    <dd>Fee {{ Money::format($current->centsOn($exampleCents)) }} → {{ Money::format($new->centsOn($exampleCents)) }}; the merchant receives {{ Money::format($exampleCents - $new->centsOn($exampleCents)) }}</dd>
                    <dt class="text-muted">Reason</dt><dd>{{ $review['reason'] }}</dd>
                </dl>
                <p class="mt-3 text-sm text-muted">Applies to new redemptions only. Redemptions already made, held ones included, keep the fee they were made with.</p>
                <form method="POST" action="{{ route('bid-admin.fees.store') }}" data-busy="Saving the fee…" class="mt-4 space-y-3">
                    @csrf
                    <input type="hidden" name="bps" value="{{ $new->bps }}">
                    <input type="hidden" name="fixed_cents" value="{{ $new->fixedCents }}">
                    <input type="hidden" name="reason" value="{{ $review['reason'] }}">
                    <input type="hidden" name="reviewed_change_id" value="{{ $current->changeId }}">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="confirm" value="1" required class="size-5 accent-primary">
                        Change the Local Goods fee to {{ $new->isNone() ? 'none' : $new->label() }} for new redemptions
                    </label>
                    <div class="flex flex-wrap gap-3">
                        <x-button type="submit">Confirm the change</x-button>
                        <a href="{{ route('bid-admin.fees') }}" class="tap-target self-center font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Cancel</a>
                    </div>
                </form>
            </x-card>
        @else
            <x-card as="section" aria-labelledby="change-heading">
                <h2 id="change-heading" class="text-xl font-semibold text-primary">Change the fee</h2>
                @error('confirm')<p class="mt-2 text-sm font-semibold text-hold"><span aria-hidden="true">⚑</span> {{ $message }}</p>@enderror
                <form method="POST" action="{{ route('bid-admin.fees.review') }}" data-busy="Checking…" class="mt-4 grid gap-4 sm:max-w-lg">
                    @csrf
                    <x-field name="percent" id="fee-percent" label="Percent of each sale" hint="0 to {{ rtrim(MerchantFee::percent(MerchantFee::MAX_BPS), '%') }}, up to two decimals, like 2.5. 0 for none.">
                        <input id="fee-percent" name="percent" type="text" inputmode="decimal" required value="{{ old('percent', rtrim(MerchantFee::percent($current->bps), '%')) }}" class="{{ $input }}"
                               aria-describedby="fee-percent-hint{{ $errors->has('percent') ? ' fee-percent-error' : '' }}" @error('percent') aria-invalid="true" @enderror>
                    </x-field>
                    <x-field name="fixed" id="fee-fixed" label="Fixed amount per sale, in dollars" hint="0 to 1.00, like 0.10. 0 for none.">
                        <input id="fee-fixed" name="fixed" type="text" inputmode="decimal" required value="{{ old('fixed', Money::toDollars($current->fixedCents)) }}" class="{{ $input }}"
                               aria-describedby="fee-fixed-hint{{ $errors->has('fixed') ? ' fee-fixed-error' : '' }}" @error('fixed') aria-invalid="true" @enderror>
                    </x-field>
                    <x-field name="reason" id="fee-reason" label="Reason" hint="Kept in the log below with your initials.">
                        <input id="fee-reason" name="reason" type="text" required minlength="5" maxlength="500" value="{{ old('reason') }}" class="{{ $input }}"
                               aria-describedby="fee-reason-hint{{ $errors->has('reason') ? ' fee-reason-error' : '' }}" @error('reason') aria-invalid="true" @enderror>
                    </x-field>
                    <div><x-button type="submit">Review the change</x-button></div>
                </form>
            </x-card>
        @endif
    @endif

    <section aria-labelledby="log-heading" class="space-y-3">
        <h2 id="log-heading" class="text-2xl font-semibold text-primary">Every change</h2>
        @if ($changes->isEmpty())
            <x-card><p class="text-muted">No changes yet: Local Goods has never charged a fee on merchant sales.</p></x-card>
        @else
            <x-rtable label="Fee changes table">
                <table data-rtable="stack" class="w-full text-left">
                    <caption class="sr-only">Fee changes, newest first</caption>
                    <thead class="border-b border-line/70 text-sm text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold sm:px-6">When (DC time)</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Who</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Change</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Reason</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/70">
                        @foreach ($changes as $row)
                            <tr data-fee-change>
                                <td data-primary class="px-5 py-3 tabular sm:px-6">{{ $row->created_at->setTimezone(config('fraud.timezone'))->format('M j, Y g:i A') }}</td>
                                <td data-label="Who" class="px-5 py-3">{{ $row->changedBy ? User::initialsOf($row->changedBy->name) : 'Removed account' }}</td>
                                <td data-label="Change" class="px-5 py-3">{{ $figures ? ucfirst($show($row->previous_bps, $row->previous_fixed_cents)).' → '.$show($row->fee_bps, $row->fixed_cents) : ($row->fee_bps + $row->fixed_cents === 0 ? 'Set to none' : 'Fee changed') }}</td>
                                <td data-label="Reason" class="px-5 py-3">{{ $row->reason }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
        @endif
    </section>
</div>
</x-app-shell>
