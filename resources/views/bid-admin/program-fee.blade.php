@php
    use App\Models\User;
    use App\Support\BidProgramFee;
    use App\Support\Money;

    $input = 'block w-full rounded-lg border-2 border-edge bg-surface px-3 py-2 focus-visible:border-primary';
    $link = 'tap-target font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover';
    $day = fn ($d) => $d?->format('M j, Y');
    $amount = fn (int $cents) => $figures ? Money::format($cents) : 'set by the tier';
    $state = match (true) {
        ! $setUp => 'not-set',
        $year === null => 'not-started',
        $current?->isPaid() => 'paid',
        default => 'due',
    };
@endphp

<x-app-shell title="Program Fee" active="program-fee" context="Golden Triangle BID · Washington, DC">
<div class="admin-desk space-y-12">
    <header>
        <x-station-sign as="p">Program Fee</x-station-sign>
        <h1 class="page-title">Program Fee</h1>
        <p class="mt-3 text-2xl font-bold tracking-tight text-ink">The BID annual program fee for {{ $district->name }}</p>
        <p class="page-lede">
            Each district pays Local Goods an annual program fee, set by its size: the number of member businesses, which
            Local Goods sets (the district never picks its own tier). Every district pays from its program start date, with
            no free pilot year. The fee is Local Goods' own revenue, never customer funds, and it is not refundable in the app.
        </p>
        <p class="ledger-note mt-5 max-w-[42rem] text-sm" data-bid-program-fee-status>
            <span class="font-semibold">The BID program fee is {{ BidProgramFee::status() }}.</span>
            @unless ($charges) Nothing can be paid on this page. @endunless
        </p>
        @if ($returned === 'returned')
            <p class="mt-3 max-w-[42rem] text-sm" data-checkout-returned>Back from Stripe. This page shows the year as paid once Stripe confirms the payment; reload in a moment.</p>
        @elseif ($returned === 'cancelled')
            <p class="mt-3 max-w-[42rem] text-sm" data-checkout-cancelled>You left Stripe's page without paying. Nothing was charged; the year is still due.</p>
        @endif
    </header>

    <section aria-labelledby="tier-heading" class="rule-heavy" data-program-fee-state="{{ $state }}">
        <h2 id="tier-heading" class="stop-subtitle">This program year</h2>
        @if (! $setUp)
            <p class="mt-3 text-muted" data-not-set>Not set yet: Local Goods hasn't entered this district's member businesses and program start date, so nothing is due.</p>
        @else
            <dl class="mt-4 grid max-w-[60rem] border-t border-ink sm:grid-cols-[14rem_1fr] [&>dd]:border-b [&>dd]:border-line [&>dd]:py-2.5 [&>dt]:pt-2.5 sm:[&>dt]:border-b sm:[&>dt]:border-line sm:[&>dt]:pb-2.5">
                <dt class="text-muted">Tier</dt>
                <dd data-tier><span class="font-semibold">{{ $tier['name'] }}</span> ({{ $tier['businesses'] }}){{ $figures ? '; '.number_format($district->member_businesses).' member businesses on record' : '' }}</dd>
                <dt class="text-muted">Annual amount</dt>
                <dd data-annual-amount>{{ $figures ? Money::format($tier['fee_cents']).' a year' : 'Set by the tier. Judges see this page without amounts, like every public page.' }}</dd>
                <dt class="text-muted">Program start</dt>
                <dd>{{ $day($district->program_starts_on) }}</dd>
                <dt class="text-muted">This program year</dt>
                <dd data-year>
                    @if ($year === null)
                        Not started: the program starts on {{ $day($district->program_starts_on) }}.
                    @else
                        Year {{ $year['year'] }}, {{ $day($year['starts_on']) }} to {{ $day($year['ends_on']) }}:
                        <span class="font-semibold">{{ $state === 'paid' ? 'Paid' : 'Due' }}</span>
                        @if ($state === 'paid') on {{ $day($current->paid_at?->setTimezone(config('fraud.timezone'))) }} @endif
                    @endif
                </dd>
            </dl>

            @if ($state === 'due' && $current?->payment_error)
                <p class="mt-3 text-sm font-semibold text-hold" data-payment-error><span aria-hidden="true">⚑</span> The last card payment didn't go through: {{ $current->payment_error }} Try again.</p>
            @endif

            @if ($state === 'due' && $canPay)
                <form method="POST" action="{{ route('bid-admin.program-fee.pay') }}" data-busy="Opening Stripe…" class="mt-5" data-pay-program-fee>
                    @csrf
                    <x-button type="submit">Pay this year's program fee</x-button>
                    <p class="mt-2 max-w-[42rem] text-sm text-muted">Opens Stripe Checkout (test mode, no real money): card, Apple Pay or Google Pay. One payment per program year: pressing again, or in another tab, reopens the same payment.</p>
                </form>
            @endif
        @endif
    </section>

    <section aria-labelledby="history-heading" class="rule-heavy">
        <h2 id="history-heading" class="stop-subtitle">Payment history</h2>
        @if ($payments->isEmpty())
            <p class="mt-3 text-muted" data-no-payments>No program year has been paid or started yet.</p>
        @else
            <x-rtable label="Program fee payments" class="mt-3">
                <table data-rtable="stack" class="w-full text-left">
                    <caption class="sr-only">Each program year, its tier, amount and status</caption>
                    <thead class="border-b border-line/70 text-sm text-muted">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-semibold">Program year</th>
                            <th scope="col" class="px-4 py-2 font-semibold">Tier</th>
                            <th scope="col" class="px-4 py-2 font-semibold">Amount</th>
                            <th scope="col" class="px-4 py-2 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-2 font-semibold">Note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/70">
                        @foreach ($payments as $payment)
                            <tr data-payment-row="{{ $payment->program_year }}">
                                <td data-primary class="px-4 py-2">Year {{ $payment->program_year }} ({{ $day($payment->period_starts_on) }} to {{ $day($payment->period_ends_on) }})</td>
                                <td data-label="Tier" class="px-4 py-2">{{ $payment->tier }}</td>
                                <td data-label="Amount" class="px-4 py-2">{{ $amount($payment->amount_cents) }}</td>
                                <td data-label="Status" class="px-4 py-2">{{ $payment->isPaid() ? 'Paid in Stripe test mode on '.$day($payment->paid_at?->setTimezone(config('fraud.timezone'))) : 'Due (not paid)' }}</td>
                                <td data-label="Note" class="px-4 py-2">
                                    @if ($payment->admin_note)
                                        <span class="block max-w-prose">{{ $payment->admin_note }}</span>
                                        <span class="text-sm text-muted">{{ User::initialsOf($payment->noteBy?->name ?? 'Unknown') }}, {{ $day($payment->admin_noted_at) }}</span>
                                    @else
                                        <span class="text-muted">None</span>
                                    @endif
                                    @if ($writesNotes)
                                        <form method="POST" action="{{ route('bid-admin.program-fee.note', $payment) }}" data-busy="Saving the note…" class="mt-2 grid gap-2">
                                            @csrf
                                            <label for="note-{{ $payment->id }}" class="text-sm font-semibold">Note (moves no money)</label>
                                            <textarea id="note-{{ $payment->id }}" name="note" rows="2" required minlength="2" maxlength="2000" class="{{ $input }}"></textarea>
                                            <div><x-button type="submit" size="sm" variant="secondary">Save note</x-button></div>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
            <p class="mt-3 max-w-prose text-sm text-muted">The fee isn't refunded in the app. A platform admin can only record a note; a refund made in the Stripe dashboard is flagged by reconciliation and recorded nowhere else.</p>
        @endif
    </section>

    @if ($setsDistrict)
        <section aria-labelledby="district-heading" class="rule-heavy" data-district-program-form>
            <h2 id="district-heading" class="stop-subtitle">District details (Local Goods only)</h2>
            <p class="mt-2 max-w-prose text-sm text-muted">The member count sets the tier; the start date sets the program years. Every change is logged below with who, when, old and new values and the reason. The start date can't change once a year is paid.</p>
            <form method="POST" action="{{ route('bid-admin.program-fee.district') }}" data-busy="Saving…" class="mt-4 grid gap-4 sm:max-w-xl">
                @csrf
                <x-field name="member_businesses" id="d-members" label="Member businesses">
                    <input id="d-members" name="member_businesses" type="number" inputmode="numeric" min="1" required value="{{ old('member_businesses', $district->member_businesses) }}" class="{{ $input }}"
                           @error('member_businesses') aria-invalid="true" aria-describedby="d-members-error" @enderror>
                </x-field>
                <x-field name="program_starts_on" id="d-starts" label="Program start date">
                    <input id="d-starts" name="program_starts_on" type="date" required value="{{ old('program_starts_on', $district->program_starts_on?->format('Y-m-d')) }}" class="{{ $input }}"
                           @error('program_starts_on') aria-invalid="true" aria-describedby="d-starts-error" @enderror>
                </x-field>
                <x-field name="reason" id="d-reason" label="Reason" hint="Kept in the log below with your initials.">
                    <input id="d-reason" name="reason" type="text" required minlength="5" maxlength="500" value="{{ old('reason') }}" class="{{ $input }}"
                           aria-describedby="d-reason-hint{{ $errors->has('reason') ? ' d-reason-error' : '' }}" @error('reason') aria-invalid="true" @enderror>
                </x-field>
                <div><x-button type="submit">Save district details</x-button></div>
            </form>

            <h3 class="mt-8 font-semibold">Change history</h3>
            @if ($changes->isEmpty())
                <p class="mt-2 text-muted" data-no-changes>No changes yet.</p>
            @else
                <x-rtable label="District detail changes" class="mt-3">
                    <table data-rtable="stack" class="w-full text-left" data-change-history>
                        <caption class="sr-only">Each change: when, who, old and new values, and why</caption>
                        <thead class="border-b border-line/70 text-sm text-muted">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-semibold">When</th>
                                <th scope="col" class="px-4 py-2 font-semibold">Who</th>
                                <th scope="col" class="px-4 py-2 font-semibold">Member businesses</th>
                                <th scope="col" class="px-4 py-2 font-semibold">Program start</th>
                                <th scope="col" class="px-4 py-2 font-semibold">Reason</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/70">
                            @foreach ($changes as $c)
                                <tr>
                                    <td data-primary class="px-4 py-2">{{ $c->created_at->setTimezone(config('fraud.timezone'))->format('M j, Y, g:i A') }}</td>
                                    <td data-label="Who" class="px-4 py-2">{{ User::initialsOf($c->changedBy?->name ?? 'Unknown') }}</td>
                                    <td data-label="Member businesses" class="px-4 py-2">{{ $c->previous_member_businesses === null ? 'not set' : number_format($c->previous_member_businesses) }} → {{ number_format($c->member_businesses) }}</td>
                                    <td data-label="Program start" class="px-4 py-2">{{ $day($c->previous_program_starts_on) ?? 'not set' }} → {{ $day($c->program_starts_on) }}</td>
                                    <td data-label="Reason" class="px-4 py-2">{{ $c->reason }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-rtable>
            @endif
        </section>
    @endif
</div>
</x-app-shell>
