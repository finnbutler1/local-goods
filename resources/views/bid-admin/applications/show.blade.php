@php
    use App\Models\MerchantApplication;
    use App\Services\Districts\MerchantApplications;

    $input = 'block w-full rounded-lg border-2 border-edge bg-surface px-3 py-2 focus-visible:border-primary';
    $link = 'font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover';
    $a = $application;
    $canCheck = in_array($a->status, [MerchantApplication::APPLIED, MerchantApplication::ELIGIBILITY_CHECKED], true);
    $canApprove = $a->status === MerchantApplication::ELIGIBILITY_CHECKED && $a->inside_district === true;
    $canDecline = in_array($a->status, [MerchantApplication::APPLIED, MerchantApplication::ELIGIBILITY_CHECKED], true);
    $confidenceWords = fn (float $c) => $c >= 0.9 ? 'high' : ($c >= 0.7 ? 'medium' : 'low');
@endphp

<x-app-shell :title="$a->business_name.' · Merchant Applications'" active="applications" context="Golden Triangle BID · Washington, DC">
<div class="admin-desk space-y-12">
    <header>
        <p class="mb-4"><a href="{{ route('bid-admin.applications') }}" class="tap-target {{ $link }}"><span aria-hidden="true">←</span> All applications</a></p>
        <x-station-sign as="p">Application</x-station-sign>
        <h1 class="page-title">{{ $a->business_name }}</h1>
        <p class="mt-4 flex flex-wrap items-center gap-2">
            <x-badge :wrap="true">{{ $a->statusLabel() }}</x-badge>
            @if ($a->is_sample) <x-badge :wrap="true">{{ MerchantApplication::SAMPLE_LABEL }}</x-badge> @endif
        </p>
        <p class="mt-3 max-w-[52rem] text-sm text-muted">{{ MerchantApplication::APPLICANT_LABEL }}</p>
        @unless ($decide)
            <p id="application-read-only" class="ledger-note mt-4 max-w-[52rem] text-sm"><span class="font-semibold">Read-only.</span> Only a BID admin or the platform admin checks and decides applications.</p>
        @endunless
    </header>

    <div class="grid gap-x-10 gap-y-12 lg:grid-cols-2">
        <section aria-labelledby="facts-heading" class="rule-heavy">
            <h2 id="facts-heading" class="stop-subtitle">What the shop told us</h2>
            <dl class="mt-4 grid gap-x-6 gap-y-3 border-t border-ink pt-3 sm:grid-cols-[max-content_1fr]">
                <dt class="text-muted">Address</dt><dd>{{ $a->address }}</dd>
                <dt class="text-muted">Category</dt><dd>{{ $a->categoryLabel() }}</dd>
                <dt class="text-muted">Contact</dt><dd>@if ($seesEmail)<x-breakable :value="$a->contact_email" />@else{{ $a->maskedEmail() }}@endif</dd>
                <dt class="text-muted">Why independent</dt><dd class="max-w-prose whitespace-pre-line">{{ $a->independence_answer }}</dd>
                <dt class="text-muted">Applied</dt><dd class="tabular">{{ $a->created_at->setTimezone(config('fraud.timezone'))->format('M j, Y g:i A') }} (DC time)</dd>
            </dl>
            <p class="mt-3 text-sm text-muted">The shop's own answer is for people only: it is never sent to the AI.</p>
        </section>

        <section aria-labelledby="where-heading" class="rule-heavy">
            <h2 id="where-heading" class="stop-subtitle">Where it is</h2>
            @if ($a->status === MerchantApplication::APPLIED)
                <p class="mt-2 text-muted">Not checked yet. Run the eligibility check to look the address up.</p>
            @elseif ($a->latitude === null)
                <p class="mt-2 font-semibold text-hold">Not found in DC's Master Address Repository, so it can't be approved. Check the address with the shop, or decline.</p>
            @else
                <p id="where-summary" class="mt-2 font-semibold {{ $a->inside_district ? 'text-ok' : 'text-hold' }}">
                    {{ $a->inside_district ? 'Inside' : 'Outside' }} the {{ $a->district->name }}: DC matched "{{ $a->matched_address }}".
                </p>
                <div class="mt-3"><x-district-map :map="$map" :pins="$pins" labelled-by="where-summary" /></div>
                <p class="mt-2 text-sm text-muted"><a href="{{ route('district') }}" class="{{ $link }}">The full district map</a></p>
            @endif
        </section>
    </div>

    <section aria-labelledby="ai-heading" class="rule-heavy">
        <h2 id="ai-heading" class="stop-subtitle">Merchant Eligibility Matching: a suggestion</h2>
        @if ($a->ai_classification === null)
            <p class="mt-2 text-muted">Not asked yet.</p>
        @else
            <dl class="mt-4 grid max-w-[60rem] gap-x-6 gap-y-2 border-t border-ink pt-3 sm:grid-cols-[max-content_1fr]">
                <dt class="text-muted">Suggestion</dt><dd class="font-semibold">{{ str_replace('_', ' ', ucfirst($a->ai_classification)) }}{{ $a->ai_eligible ? ' (looks eligible)' : ' (does not look eligible)' }}</dd>
                <dt class="text-muted">Confidence</dt><dd class="tabular">{{ round(100 * $a->ai_confidence) }}% ({{ $confidenceWords($a->ai_confidence) }}){{ $a->ai_needs_review ? ' · flagged for a closer look' : '' }}</dd>
                <dt class="text-muted">Reasoning</dt><dd class="max-w-prose">{{ $a->ai_reasoning }}</dd>
                <dt class="text-muted">Source</dt><dd class="tabular">{{ $a->ai_model === \Database\Seeders\DatabaseSeeder::SEEDED_SUGGESTION ? 'Seeded example, not an AI answer' : 'Prompt '.$a->ai_prompt_version.' · '.$a->ai_model }}</dd>
            </dl>
            <p class="mt-4 max-w-[60rem] border-t-[3px] border-primary bg-primary-tint px-4 py-3 text-sm">
                <span class="font-semibold">Suggestion only: a BID admin decides.</span> The AI saw only the name, address and category the shop gave
                (no license activity, no email, not the shop's own answer). It judged from its own training knowledge and did not search the web,
                so it may not know about recent sales, closures or new owners.
            </p>
        @endif
    </section>

    <section aria-labelledby="decide-heading" class="decision-panel p-5 sm:p-6">
        <h2 id="decide-heading" class="stop-subtitle">Decide</h2>
        <fieldset class="contents" @disabled(! $decide)>
            <div class="mt-4 space-y-6">
                @if ($canCheck)
                    <form method="POST" action="{{ route('bid-admin.applications.check', $a) }}" data-busy="Checking the address and asking the AI…">
                        @csrf
                        <x-button type="submit" variant="secondary" :disabled="! $decide">{{ $a->status === MerchantApplication::APPLIED ? 'Run the eligibility check' : 'Run the check again' }}</x-button>
                        <p class="mt-1 text-sm text-muted">Looks the address up in DC's Master Address Repository, checks the boundary and asks the AI once.</p>
                    </form>
                @endif
                @if ($canApprove || $canDecline)
                    <div class="grid gap-6 md:grid-cols-2">
                        @if ($a->status === MerchantApplication::ELIGIBILITY_CHECKED)
                            <form method="POST" action="{{ route('bid-admin.applications.approve', $a) }}" data-busy="Approving…" class="space-y-3">
                                @csrf
                                <x-field name="reason" id="approve-reason" label="Reason to approve" hint="The shop sees this.">
                                    <textarea id="approve-reason" name="reason" rows="2" required minlength="5" maxlength="1000" class="{{ $input }}" aria-describedby="approve-reason-hint" @disabled(! $canApprove)></textarea>
                                </x-field>
                                <x-button type="submit" :disabled="! $decide || ! $canApprove">Approve for the district</x-button>
                                @unless ($canApprove)<p class="text-sm text-muted">{{ MerchantApplications::NEEDS_INSIDE }}</p>@endunless
                            </form>
                        @endif
                        @if ($canDecline)
                            <form method="POST" action="{{ route('bid-admin.applications.decline', $a) }}" data-busy="Declining…" class="space-y-3">
                                @csrf
                                <x-field name="reason" id="decline-reason" label="Reason to decline" hint="The shop sees this.">
                                    <textarea id="decline-reason" name="reason" rows="2" required minlength="5" maxlength="1000" class="{{ $input }}" aria-describedby="decline-reason-hint"></textarea>
                                </x-field>
                                <x-button type="submit" variant="secondary" :disabled="! $decide">Decline</x-button>
                            </form>
                        @endif
                    </div>
                @endif
                @if (! $canCheck && ! $canDecline)
                    <p class="text-muted">
                        @switch($a->status)
                            @case(MerchantApplication::DECLINED) Declined: {{ $a->decline_reason }} @break
                            @case(MerchantApplication::BID_APPROVED) Approved. Waiting for the shop to sign in from its status link and set up Stripe payouts. @break
                            @case(MerchantApplication::STRIPE_ONBOARDING) The shop is setting up Stripe payouts. It goes live when Stripe confirms. @break
                            @default Live: employees can spend credits at {{ $a->merchant?->name ?? 'this shop' }}.
                        @endswitch
                    </p>
                @endif
            </div>
        </fieldset>
    </section>

    <section aria-labelledby="history-heading" class="rule-heavy space-y-3">
        <h2 id="history-heading" class="stop-subtitle">Every step</h2>
        <x-rtable label="Application steps table">
            <table data-rtable="stack" class="w-full text-left">
                <caption class="sr-only">Every step of this application, oldest first</caption>
                <thead class="border-b border-line/70 text-sm text-muted">
                    <tr>
                        <th scope="col" class="px-5 py-3 font-semibold sm:px-6">When (DC time)</th>
                        <th scope="col" class="px-5 py-3 font-semibold">Step</th>
                        <th scope="col" class="px-5 py-3 font-semibold">Who</th>
                        <th scope="col" class="px-5 py-3 font-semibold">Why</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/70">
                    @foreach ($a->events as $event)
                        <tr data-application-event>
                            <td data-primary class="px-5 py-3 whitespace-nowrap [font-variant-numeric:tabular-nums] sm:px-6">{{ $event->created_at->setTimezone(config('fraud.timezone'))->format('M j, Y g:i A') }}</td>
                            <td data-label="Step" class="px-5 py-3">{{ MerchantApplication::STEPS[$event->to_status] ?? 'Declined' }}</td>
                            <td data-label="Who" class="px-5 py-3">{{ $event->actorLabel() === 'You (the applicant)' ? 'Applicant' : $event->actorLabel() }}</td>
                            <td data-label="Why" class="px-5 py-3">{{ $event->reason }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-rtable>
    </section>
</div>
</x-app-shell>
