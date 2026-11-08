@php
    use App\Http\Controllers\BidAdmin\PartnershipController;
    use App\Models\Partnership;
    use App\Models\User;
    use App\Support\Money;

    $input = 'block w-full rounded-lg border-2 border-edge bg-surface px-3 py-2 focus-visible:border-primary';
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('M j, Y') : 'No end date';
    $termText = function (string $term, $value) use ($figures, $date) {
        return match ($term) {
            'status' => ucfirst((string) $value),
            'exclusive' => $value ? 'Exclusive' : 'Not exclusive',
            'starts_on', 'ends_on' => $date($value),
            'revenue_share_bps' => $figures ? Partnership::percent((int) $value) : ((int) $value > 0 ? 'A share agreed' : 'None'),
            default => (string) $value,
        };
    };
@endphp

<x-app-shell title="Partnership" active="partnership" context="Golden Triangle BID · Washington, DC">
<div class="admin-desk space-y-12">
    <header>
        <x-station-sign as="p">Partnership</x-station-sign>
        <h1 class="page-title">Partnership</h1>
        <p class="mt-3 text-2xl font-bold tracking-tight text-ink">The BID partnership for {{ $district->name }}</p>
        <p class="page-lede">
            The Business Improvement District introduces Local Goods to employers and merchants in its district. This page
            records what the BID and Local Goods agreed: whether it is exclusive, for how long, any revenue share and what
            data the BID gets.
        </p>
        <ul class="mt-5 max-w-[42rem] border-t border-ink text-muted [&>li]:border-b [&>li]:border-line [&>li]:py-2">
            <li>A district can have only one active exclusive partnership at a time. The database refuses a second one.</li>
            <li>Every change is logged below: who, when and why.</li>
            <li>The revenue share is worked out from activity in the district for display only. No money moves to the BID in this prototype.</li>
        </ul>
        @unless ($change)
            <p id="partnership-read-only" class="ledger-note mt-5 max-w-[42rem] text-sm"><span class="font-semibold">Read-only.</span> Only a BID admin or the Local Goods platform admin changes the partnership.</p>
        @endunless
    </header>

    @if (! $partnership)
        <div class="ledger-note"><p class="text-muted" data-no-partnership>No partnership is recorded for this district yet.@if ($change) Start one below: it begins as a draft, and every step is logged with your initials.@endif</p></div>
    @else
        @if ($partnership->is_demo)
            <p class="border-t-[3px] border-hold bg-hold-bg px-4 py-3 font-semibold text-hold" data-demo-partnership>{{ Partnership::DEMO_LABEL }}</p>
        @endif

        <section aria-labelledby="terms-heading" class="rule-heavy">
            <div class="flex flex-wrap items-center gap-3">
                <h2 id="terms-heading" class="stop-subtitle">Terms</h2>
                <x-badge :tone="$partnership->status === Partnership::STATUS_ACTIVE ? 'ok' : 'neutral'">{{ $partnership->statusLabel() }}</x-badge>
                @if ($partnership->exclusive) <x-badge tone="primary">Exclusive</x-badge> @endif
            </div>
            <dl class="mt-4 grid max-w-[60rem] border-t border-ink sm:grid-cols-[12rem_1fr] [&>dd]:border-b [&>dd]:border-line [&>dd]:py-2.5 [&>dt]:pt-2.5 sm:[&>dt]:border-b sm:[&>dt]:border-line sm:[&>dt]:pb-2.5">
                <dt class="text-muted">BID</dt><dd class="font-semibold">{{ $partnership->bid->name }} BID</dd>
                <dt class="text-muted">District</dt><dd>{{ $district->name }}</dd>
                <dt class="text-muted">Exclusive</dt>
                <dd>{{ $partnership->exclusive ? 'Yes: Local Goods works with this BID only in this district, and no other BID can hold an exclusive partnership here while this one is active.' : 'No' }}</dd>
                <dt class="text-muted">Dates</dt><dd>{{ $partnership->starts_on->format('M j, Y') }} to {{ $partnership->ends_on?->format('M j, Y') ?? 'no end date' }}</dd>
                <dt class="text-muted">Revenue share</dt>
                <dd data-revenue-share>{{ $figures ? Partnership::percent($partnership->revenue_share_bps).' of what Local Goods earns in the district' : $partnership->revenueShareWords() }}</dd>
                <dt class="text-muted">Data terms</dt>
                <dd><span class="block max-w-prose">{{ $partnership->data_terms }}</span>
                    <a href="{{ route('bid-admin.impact.csv') }}" class="tap-target block font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover">Download the aggregate data (CSV)</a>
                </dd>
            </dl>
        </section>

        <section aria-labelledby="share-heading" class="rule-heavy">
            <h2 id="share-heading" class="stop-subtitle">What the revenue share comes to</h2>
            @if ($figures)
                <dl class="mt-6 grid max-w-[60rem] gap-x-6 gap-y-8 sm:grid-cols-2">
                    <div class="rule-top">
                        <dt class="figure-label">This month (DC time)</dt>
                        <dd class="money mt-2 text-4xl" data-share-month>{{ Money::format($month['share_cents']) }}</dd>
                        <dd class="text-sm text-muted">of {{ Money::format($month['revenue_cents']) }} Local Goods earned in the district</dd>
                    </div>
                    <div class="rule-top">
                        <dt class="figure-label">Since the partnership started</dt>
                        <dd class="money mt-2 text-4xl" data-share-since>{{ Money::format($since['share_cents']) }}</dd>
                        <dd class="text-sm text-muted">of {{ Money::format($since['revenue_cents']) }} Local Goods earned in the district</dd>
                    </div>
                </dl>
                <details class="mt-4">
                    <summary class="cursor-pointer font-semibold text-primary">How we count this</summary>
                    <p class="mt-2 max-w-prose text-sm text-muted">
                        What Local Goods earned in the district is the sum of its own ledger lines tied to the district:
                        the merchant fee on cleared sales at the district's merchants (less any given back on refunds) and the
                        platform fee on credits bought by employers whose program runs here. The share is that times the rate,
                        rounded to the nearest cent. Both fees are set to charge nothing today, so this is $0.00. Customer
                        credits are never revenue. Display only: nothing is paid to the BID.
                    </p>
                </details>
            @else
                <p class="mt-2 text-muted">Judges see this page without amounts, like every public page. The share is worked out from the district's activity and is display only.</p>
            @endif
        </section>
    @endif

    {{-- Start (no partnership yet) or change one: a BID admin or the platform admin, in two steps. --}}
    @php($starting = $partnership === null)
        @if ($change)
            @if ($review)
                @php($new = $review['terms'])
                <section aria-labelledby="review-heading" class="decision-panel p-5 sm:p-6" data-partnership-review>
                    <h2 id="review-heading" class="stop-subtitle">{{ $starting ? 'Check the new partnership' : 'Check the change' }}</h2>
                    <x-rtable label="Partnership change table" class="mt-3">
                        <table data-rtable="stack" class="w-full text-left">
                            <caption class="sr-only">Each term now and after the change</caption>
                            <thead class="border-b border-line/70 text-sm text-muted">
                                <tr><th scope="col" class="px-4 py-2 font-semibold">Term</th><th scope="col" class="px-4 py-2 font-semibold">Now</th><th scope="col" class="px-4 py-2 font-semibold">After</th></tr>
                            </thead>
                            <tbody class="divide-y divide-line/70">
                                @foreach (Partnership::TERMS as $term)
                                    @php($now = $partnership?->terms()[$term])
                                    <tr @class(['font-semibold' => ! $starting && $now !== $new[$term]])>
                                        <td data-primary class="px-4 py-2">{{ ucfirst(PartnershipController::TERM_LABELS[$term]) }}{{ ! $starting && $now !== $new[$term] ? ' (changes)' : '' }}</td>
                                        <td data-label="Now" class="px-4 py-2">{{ $starting ? '—' : $termText($term, $now) }}</td>
                                        <td data-label="After" class="px-4 py-2">{{ $termText($term, $new[$term]) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-rtable>
                    <p class="mt-3"><span class="text-muted">Reason:</span> {{ $review['reason'] }}</p>
                    <form method="POST" action="{{ route('bid-admin.partnership.store') }}" data-busy="Saving the partnership…" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="status" value="{{ $new['status'] }}">
                        <input type="hidden" name="exclusive" value="{{ $new['exclusive'] ? 1 : 0 }}">
                        <input type="hidden" name="starts_on" value="{{ $new['starts_on'] }}">
                        <input type="hidden" name="ends_on" value="{{ $new['ends_on'] }}">
                        <input type="hidden" name="revenue_share_bps" value="{{ $new['revenue_share_bps'] }}">
                        <input type="hidden" name="data_terms" value="{{ $new['data_terms'] }}">
                        <input type="hidden" name="reason" value="{{ $review['reason'] }}">
                        <input type="hidden" name="reviewed_change_id" value="{{ $partnership?->latestChangeId() ?? 0 }}">
                        <label class="flex min-h-11 items-center gap-2">
                            <input type="checkbox" name="confirm" value="1" required class="size-5 accent-primary">
                            {{ $starting ? 'Start this partnership as a draft' : 'Save these partnership terms' }}
                        </label>
                        <div class="flex flex-wrap gap-3">
                            <x-button type="submit">{{ $starting ? 'Start the partnership' : 'Confirm the change' }}</x-button>
                            <a href="{{ route('bid-admin.partnership') }}" class="tap-target self-center font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover">Cancel</a>
                        </div>
                    </form>
                </section>
            @else
                @php($t = $partnership?->terms() ?? [
                    'status' => Partnership::STATUS_DRAFT, 'exclusive' => true, 'starts_on' => now(config('fraud.timezone'))->toDateString(),
                    'ends_on' => null, 'revenue_share_bps' => 0, 'data_terms' => Partnership::DEFAULT_DATA_TERMS,
                ])
                <section aria-labelledby="change-heading" class="rule-heavy" data-partnership-form="{{ $starting ? 'start' : 'change' }}">
                    <h2 id="change-heading" class="stop-subtitle">{{ $starting ? 'Start a partnership' : 'Change the partnership' }}</h2>
                    @foreach (['confirm', 'terms'] as $key)
                        @error($key)<p class="mt-2 text-sm font-semibold text-hold"><span aria-hidden="true">⚑</span> {{ $message }}</p>@enderror
                    @endforeach
                    <form method="POST" action="{{ route('bid-admin.partnership.review') }}" data-busy="Checking…" class="mt-4 grid gap-4 sm:max-w-xl">
                        @csrf
                        @if ($starting)
                            <p class="text-sm text-muted">It starts as a draft. Change its status to active once the BID and Local Goods agree; it never goes back.</p>
                        @else
                            <x-field name="status" id="p-status" label="Status" hint="Draft, then active, then ended. It never goes back.">
                                <select id="p-status" name="status" class="{{ $input }}" aria-describedby="p-status-hint{{ $errors->has('status') ? ' p-status-error' : '' }}" @error('status') aria-invalid="true" @enderror>
                                    @foreach (Partnership::STATUSES as $status)
                                        <option value="{{ $status }}" @selected(old('status', $t['status']) === $status)>{{ ucfirst($status) }}</option>
                                    @endforeach
                                </select>
                            </x-field>
                        @endif
                        <div>
                            <input type="hidden" name="exclusive" value="0">
                            <label class="flex min-h-11 items-center gap-2 font-semibold">
                                <input type="checkbox" name="exclusive" value="1" class="size-5 accent-primary" @checked(old('exclusive', $t['exclusive']))>
                                Exclusive in this district
                            </label>
                        </div>
                        <x-field name="starts_on" id="p-starts" label="Start date">
                            <input id="p-starts" name="starts_on" type="date" required value="{{ old('starts_on', $t['starts_on']) }}" class="{{ $input }}"
                                   @error('starts_on') aria-invalid="true" aria-describedby="p-starts-error" @enderror>
                        </x-field>
                        <x-field name="ends_on" id="p-ends" label="End date" hint="Leave empty for no end date.">
                            <input id="p-ends" name="ends_on" type="date" value="{{ old('ends_on', $t['ends_on']) }}" class="{{ $input }}"
                                   aria-describedby="p-ends-hint{{ $errors->has('ends_on') ? ' p-ends-error' : '' }}" @error('ends_on') aria-invalid="true" @enderror>
                        </x-field>
                        <x-field name="revenue_share" id="p-share" label="Revenue share, percent of what Local Goods earns in the district" hint="0 to {{ rtrim(Partnership::percent(Partnership::MAX_REVENUE_SHARE_BPS), '%') }}, like 10 or 12.5. 0 for none.">
                            <input id="p-share" name="revenue_share" type="text" inputmode="decimal" required value="{{ old('revenue_share', rtrim(Partnership::percent($t['revenue_share_bps']), '%')) }}" class="{{ $input }}"
                                   aria-describedby="p-share-hint{{ $errors->has('revenue_share') ? ' p-share-error' : '' }}" @error('revenue_share') aria-invalid="true" @enderror>
                        </x-field>
                        <x-field name="data_terms" id="p-data" label="Data terms">
                            <textarea id="p-data" name="data_terms" rows="3" required maxlength="2000" class="{{ $input }}"
                                      @error('data_terms') aria-invalid="true" aria-describedby="p-data-error" @enderror>{{ old('data_terms', $t['data_terms']) }}</textarea>
                        </x-field>
                        <x-field name="reason" id="p-reason" label="Reason" hint="Kept in the log below with your initials.">
                            <input id="p-reason" name="reason" type="text" required minlength="5" maxlength="500" value="{{ old('reason') }}" class="{{ $input }}"
                                   aria-describedby="p-reason-hint{{ $errors->has('reason') ? ' p-reason-error' : '' }}" @error('reason') aria-invalid="true" @enderror>
                        </x-field>
                        <div><x-button type="submit">{{ $starting ? 'Review the new partnership' : 'Review the change' }}</x-button></div>
                    </form>
                </section>
            @endif
        @endif

    @if ($partnership)
        <section aria-labelledby="log-heading" class="rule-heavy space-y-3">
            <h2 id="log-heading" class="stop-subtitle">Every change</h2>
            <x-rtable label="Partnership changes table">
                <table data-rtable="stack" class="w-full text-left">
                    <caption class="sr-only">Partnership changes, newest first</caption>
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
                            <tr data-partnership-change>
                                <td data-primary class="px-5 py-3 whitespace-nowrap [font-variant-numeric:tabular-nums] sm:px-6">{{ $row->created_at->setTimezone(config('fraud.timezone'))->format('M j, Y g:i A') }}</td>
                                <td data-label="Who" class="px-5 py-3">{{ $row->changedBy ? User::initialsOf($row->changedBy->name) : 'Demo seed' }}</td>
                                <td data-label="Change" class="px-5 py-3">
                                    @if ($row->before === null)
                                        Created
                                    @else
                                        @foreach ($row->changedTerms() as $term)
                                            <span class="block">{{ ucfirst(PartnershipController::TERM_LABELS[$term]) }}: {{ $termText($term, $row->before[$term]) }} → {{ $termText($term, $row->after[$term]) }}</span>
                                        @endforeach
                                    @endif
                                </td>
                                <td data-label="Reason" class="px-5 py-3">{{ $row->reason }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
        </section>
    @endif
</div>
</x-app-shell>
