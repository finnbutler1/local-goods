@php
    use App\Models\Correction;
    use App\Models\CorrectionAction;
    use App\Support\CorrectionReasons;
    use App\Support\Money;

    $figures = \App\Support\MerchantFee::showsFiguresTo(auth()->user());
    $input = 'block w-full rounded-xl border-2 bg-surface px-3 py-2 focus-visible:border-primary';
    $who = fn (Correction $c) => match ($c->requested_by_role) {
        \App\Enums\Role::Employee->value => 'Employee '.$c->employee?->initials(),
        \App\Enums\Role::Merchant->value => $c->merchant?->name.' (merchant)',
        \App\Enums\Role::EmployerAdmin->value => ($c->employer?->name ?? 'Employer').' HR',
        default => 'Seeded demo example',
    };
    $tone = fn (string $status) => match ($status) {
        Correction::STATUS_COMPLETED => 'ok',
        Correction::STATUS_FAILED => 'hold',
        default => 'neutral',
    };
    $kind = fn (CorrectionAction $a) => match ($a->kind) {
        CorrectionAction::KIND_TRANSFER_REVERSAL => 'Stripe transfer reversal',
        CorrectionAction::KIND_CARD_REFUND => 'Stripe card refund on '.$a->creditPurchase?->reference,
        CorrectionAction::KIND_ADJUSTMENT_TRANSFER => 'Owed Stripe transfer sent (existing payout path)',
        CorrectionAction::KIND_FEE_ONLY => 'Nothing to reverse: all of it was the Local Goods fee',
        default => 'Simulated'.($a->creditPurchase ? ' return on '.$a->creditPurchase->reference : '').', no real money',
    };
@endphp

<x-app-shell title="Corrections" active="corrections" context="Golden Triangle BID · Washington, DC">
<div class="space-y-10">
    <header class="max-w-3xl">
        <x-eyebrow class="text-accent-ink">Refunds and corrections</x-eyebrow>
        <h1 class="mt-1 text-title font-semibold text-primary">How money mistakes get fixed</h1>
        <p class="mt-3 text-lg text-muted">
            Employees, merchants and employers ask here when money went wrong. The Local Goods platform admin decides each one and writes why;
            the person who asked sees that reason. Nothing is edited or deleted: every fix is a new line in the ledger, linked to the request.
        </p>
        <ul class="mt-4 list-disc space-y-1 pl-5 text-muted">
            <li><span class="font-semibold text-ink">Redemption refund:</span> credits back to the employee. Paid through Stripe, that part of the merchant's transfer is reversed; simulated, it is deducted from what the merchant is owed. Menu items are refunded with the tax charged on them.</li>
            <li><span class="font-semibold text-ink">Merchant adjustment:</span> a Stripe transfer the merchant is owed but never got is sent, for exactly that amount, through the same payout path as every transfer.</li>
            <li><span class="font-semibold text-ink">Refund of unspent credits:</span> credits not yet given to employees go back to the employer's card as Stripe refunds, newest payment first.</li>
        </ul>
        <p class="mt-3 flex flex-wrap items-center gap-2 text-sm text-muted">
            <x-money-mode />
            Corrections are never sent to Redemption Fraud Detection and never change a fraud decision.
            Stripe's webhook writes the ledger lines for Stripe refunds, as it does for card payments.
        </p>
        @unless ($decide)
            <p id="corrections-read-only" class="mt-4 rounded-2xl bg-band p-4 text-sm"><span class="font-semibold">Read-only.</span> Only the Local Goods platform admin approves, rejects, retries or closes corrections.</p>
        @endunless
    </header>

    <section aria-labelledby="open-heading" class="space-y-4">
        <h2 id="open-heading" class="text-2xl font-semibold text-primary">Open ({{ $open->count() }})</h2>
        @if ($open->isEmpty())
            <x-card><p class="text-muted">No open requests. When someone asks for a correction it shows here.</p></x-card>
        @else
            <ul class="space-y-4">
                @foreach ($open as $row)
                    @php
                        $c = $row['correction'];
                        $bag = $errors->getBag('correction'.$c->id);
                        $id = 'correction-'.$c->id;
                    @endphp
                    <x-card as="li" id="{{ $id }}" class="scroll-mt-24" data-correction-row="{{ $c->status }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-xl font-semibold">{{ $c->typeLabel() }} <span class="tabular text-muted">#{{ $c->id }}</span></h3>
                                <p class="mt-1 text-sm text-muted">Asked {{ $c->created_at->setTimezone('America/New_York')->format('M j, g:i A') }} by {{ $who($c) }}</p>
                            </div>
                            <x-badge :tone="$tone($c->status)">{{ $c->statusLabel() }}</x-badge>
                        </div>

                        <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="font-semibold text-muted">About</dt>
                                <dd class="mt-0.5">
                                    @if ($c->type === Correction::TYPE_REDEMPTION_REFUND)
                                        {{ Money::format($c->redemption->amount_cents) }} at {{ $c->redemption->merchant->name }} by {{ $c->redemption->employee->initials() }},
                                        {{ $c->redemption->created_at->setTimezone('America/New_York')->format('M j') }} ({{ $c->redemption->reference() }})
                                        @if ($for = $c->redemption->itemsSummary())
                                            <span class="block break-words">{{ $for }}</span>
                                        @endif
                                        @include('partials.tax-breakdown', ['tax' => $c->redemption->taxBreakdown(), 'compact' => true, 'class' => 'text-muted'])
                                    @elseif ($c->type === Correction::TYPE_MERCHANT_ADJUSTMENT)
                                        {{ Money::format($c->payout->amount_cents) }} payout to {{ $c->payout->merchant->name }}, status: {{ str_replace('_', ' ', $c->payout->status) }}
                                        @if ($c->payout->stripe_transfer_id)<span class="block font-mono wrap-anywhere-safe">{{ $c->payout->stripe_transfer_id }}</span>@endif
                                        @if ($c->payout->failure_reason && $c->payout->status !== 'sent')<span class="block text-hold">{{ $c->payout->failure_reason }}</span>@endif
                                    @else
                                        {{ $c->employer->name }}'s unspent credits
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="font-semibold text-muted">What went wrong</dt>
                                <dd class="mt-0.5">{{ $c->reasonLabel() }}@if ($c->note)<span class="block text-muted">“{{ $c->note }}”</span>@endif</dd>
                            </div>
                            <div>
                                <dt class="font-semibold text-muted">{{ $c->amount_cents === null ? 'Left to refund' : 'Approved' }}</dt>
                                <dd class="tabular mt-0.5">{{ $c->amount_cents === null ? Money::format(max(0, $row['left_cents'])) : $c->amountLabel() }}
                                    @if ($c->tax_cents)<span class="block text-muted">{{ Money::format($c->subtotal_cents) }} + {{ Money::format($c->tax_cents) }} tax</span>@endif
                                    @if ($figures && $c->fee_cents > 0)<span class="block text-muted" data-fee-returned>Includes {{ Money::format($c->fee_cents) }} Local Goods fee given back</span>@endif
                                </dd>
                            </div>
                            <div>
                                <dt class="font-semibold text-muted">How it would be paid</dt>
                                <dd class="mt-0.5">
                                    @if (isset($row['path']))
                                        <span @class(['text-hold' => $row['path']['path'] === 'blocked'])>{{ $row['path']['text'] }}</span>
                                    @elseif (isset($row['plan']))
                                        @forelse ($row['plan'] as [$purchase, $take])
                                            <span class="block">{{ Money::format($take) }} on {{ $purchase->reference }} ({{ $purchase->isStripe() ? 'Stripe refund to the card' : 'simulated' }})</span>
                                        @empty
                                            <span class="text-hold">Nothing is unspent to refund.</span>
                                        @endforelse
                                    @else
                                        The existing payout path (same as Reconciliation → Retry owed transfers).
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        @if ($c->actions->isNotEmpty())
                            <ul class="mt-4 space-y-1 rounded-xl bg-band p-3 text-sm" aria-label="Steps of correction {{ $c->id }}">
                                @foreach ($c->actions as $action)
                                    <li data-action="{{ $action->status }}">
                                        <span class="tabular font-semibold">{{ Money::format($action->amount_cents) }}</span> · {{ $kind($action) }} · {{ $action->status }}
                                        @if ($action->stripe_id)<span class="font-mono wrap-anywhere-safe">{{ $action->stripe_id }}</span>@endif
                                        @if ($action->failure_reason)<span class="block text-hold">{{ $action->failure_reason }}</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($c->decision_reason)
                            <p class="mt-3 text-sm"><span class="font-semibold">Decision</span> by {{ $c->decidedBy ? \App\Models\User::initialsOf($c->decidedBy->name) : 'Local Goods' }}: “{{ $c->decision_reason }}”</p>
                        @endif

                        @if ($decide && $c->status === Correction::STATUS_REQUESTED)
                            <form method="POST" action="{{ route('bid-admin.corrections.approve', $c) }}" data-busy="Saving your decision…" class="mt-4 space-y-3 rounded-xl border border-line bg-surface p-4">
                                @csrf
                                @if ($c->type === Correction::TYPE_REDEMPTION_REFUND && $row['items']->isNotEmpty())
                                    <fieldset class="space-y-2" @if ($bag->has('items')) aria-invalid="true" aria-describedby="{{ $id }}-items-error" @endif>
                                        <legend class="font-semibold">Items to refund (tax comes from the order's own snapshot)</legend>
                                        @foreach ($row['items'] as ['item' => $item, 'left' => $left])
                                            <div class="flex flex-wrap items-center gap-3">
                                                <label for="{{ $id }}-item-{{ $item->id }}" class="min-w-40 grow">{{ $item->label() }} · {{ Money::format($item->unit_price_cents) }} each <span class="text-muted">({{ $left }} left)</span></label>
                                                <input id="{{ $id }}-item-{{ $item->id }}" name="items[{{ $item->id }}]" type="number" inputmode="numeric" min="0" max="{{ $left }}" value="{{ $bag->any() ? old('items.'.$item->id, 0) : 0 }}" @disabled($left === 0) class="{{ $input }} w-24 border-muted">
                                            </div>
                                        @endforeach
                                        @if ($bag->has('items'))
                                            <p id="{{ $id }}-items-error" class="text-sm font-semibold text-hold"><span aria-hidden="true">⚑</span> {{ $bag->first('items') }}</p>
                                        @endif
                                    </fieldset>
                                @elseif ($c->type !== Correction::TYPE_MERCHANT_ADJUSTMENT)
                                    <x-field name="amount" :id="$id.'-amount'" label="Amount to refund ($)" :hint="'Up to '.Money::format(max(0, $row['left_cents'])).'.'" :error="$bag->first('amount') ?: null">
                                        <input id="{{ $id }}-amount" name="amount" type="text" inputmode="decimal" required value="{{ $bag->any() ? old('amount') : Money::toDollars(max(0, $row['left_cents'])) }}"
                                               aria-describedby="{{ $id }}-amount-hint{{ $bag->has('amount') ? ' '.$id.'-amount-error' : '' }}" @if ($bag->has('amount')) aria-invalid="true" @endif
                                               class="{{ $input }} max-w-40 {{ $bag->has('amount') ? 'border-hold' : 'border-muted' }}">
                                    </x-field>
                                @endif
                                <x-field name="reason" :id="$id.'-reason'" label="Reason (required; the person who asked sees it)" hint="5 to 500 characters. No names or card details." :error="$bag->first('reason') ?: null">
                                    <textarea id="{{ $id }}-reason" name="reason" rows="2" required minlength="5" maxlength="500"
                                              aria-describedby="{{ $id }}-reason-hint{{ $bag->has('reason') ? ' '.$id.'-reason-error' : '' }}" @if ($bag->has('reason')) aria-invalid="true" @endif
                                              class="{{ $input }} {{ $bag->has('reason') ? 'border-hold' : 'border-muted' }}">{{ $bag->any() ? old('reason') : '' }}</textarea>
                                </x-field>
                                <div class="grid grid-cols-2 gap-3 sm:flex sm:flex-wrap sm:gap-2">
                                    <x-button type="submit" size="sm" aria-label="Approve correction {{ $c->id }}">Approve</x-button>
                                    <x-button type="submit" size="sm" variant="danger" class="bg-surface" formaction="{{ route('bid-admin.corrections.reject', $c) }}" aria-label="Reject correction {{ $c->id }}">Reject</x-button>
                                </div>
                            </form>
                        @elseif ($decide && in_array($c->status, [Correction::STATUS_FAILED, Correction::STATUS_PROCESSING], true))
                            <div class="mt-4 flex flex-wrap items-start gap-3">
                                <form method="POST" action="{{ route('bid-admin.corrections.retry', $c) }}" data-busy="Asking Stripe again…">
                                    @csrf
                                    <x-button type="submit" size="sm">{{ $c->status === Correction::STATUS_FAILED ? 'Retry safely' : 'Check with Stripe again' }}</x-button>
                                </form>
                                @if ($c->status === Correction::STATUS_FAILED)
                                    <form method="POST" action="{{ route('bid-admin.corrections.close', $c) }}" data-busy="Closing…" class="grow space-y-2">
                                        @csrf
                                        <x-field name="reason" :id="$id.'-close-reason'" label="Or close it without a refund (reason required)" :error="$bag->first('reason') ?: null">
                                            <input id="{{ $id }}-close-reason" name="reason" type="text" required minlength="5" maxlength="500" class="{{ $input }} border-muted" @if ($bag->has('reason')) aria-invalid="true" aria-describedby="{{ $id }}-close-reason-error" @endif>
                                        </x-field>
                                        <x-button type="submit" size="sm" variant="danger" class="bg-surface">Close without a refund</x-button>
                                    </form>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-muted">Retrying never acts twice: a step whose answer was lost asks Stripe again with the same idempotency key.</p>
                        @endif
                    </x-card>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="done-heading" class="space-y-4">
        <h2 id="done-heading" class="text-2xl font-semibold text-primary">Decided</h2>
        @if ($done->isEmpty())
            <x-card><p class="text-muted">Nothing decided yet.</p></x-card>
        @else
            <x-rtable label="Decided corrections">
                <table data-rtable="stack" class="w-full text-left text-sm">
                    <caption class="sr-only">The newest {{ \App\Http\Controllers\BidAdmin\CorrectionsController::DONE_SHOWN }} decided corrections, with what they were about, the amount, the outcome and the reason given</caption>
                    <thead class="border-b border-line/70 text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold">Correction</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Asked by</th>
                            <th scope="col" class="px-5 py-3 text-right font-semibold">Amount</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Outcome</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Reason given</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/70">
                        @foreach ($done as $c)
                            <tr id="correction-{{ $c->id }}" data-correction-row="{{ $c->status }}">
                                <th scope="row" data-primary class="px-5 py-3 font-semibold">{{ $c->typeLabel() }} #{{ $c->id }}
                                    <span class="block font-normal text-muted">{{ $c->redemption ? $c->redemption->merchant->name.' · '.$c->redemption->reference() : ($c->payout ? $c->payout->merchant->name : $c->employer?->name) }}</span>
                                </th>
                                <td data-label="Asked by" class="px-5 py-3">{{ $who($c) }}<span class="block text-muted">{{ $c->reasonLabel() }}</span></td>
                                <td data-label="Amount" class="tabular px-5 py-3 text-right">{{ $c->amountLabel() }}
                                    @if ($c->tax_cents)<span class="block text-muted">incl. {{ Money::format($c->tax_cents) }} tax</span>@endif
                                    @if ($figures && $c->fee_cents > 0)<span class="block text-muted" data-fee-returned>incl. {{ Money::format($c->fee_cents) }} Local Goods fee given back</span>@endif
                                </td>
                                <td data-label="Outcome" class="px-5 py-3"><x-badge :tone="$tone($c->status)" :wrap="true">{{ $c->statusLabel() }}</x-badge>
                                    @if ($c->actions->isNotEmpty())<span class="mt-1 block text-muted">{{ $c->actions->map(fn ($a) => $kind($a))->unique()->implode('; ') }}</span>@endif
                                </td>
                                <td data-label="Reason given" class="px-5 py-3">“{{ $c->decision_reason }}”
                                    <span class="block text-muted">{{ $c->decidedBy ? \App\Models\User::initialsOf($c->decidedBy->name) : 'Local Goods' }}, {{ ($c->decided_at ?? $c->updated_at)->setTimezone('America/New_York')->format('M j') }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
        @endif
    </section>
</div>
</x-app-shell>
