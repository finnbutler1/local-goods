{{-- "How Local Goods makes money" (ADR 0030, ADR 0042, ADR 0044): plain words for someone outside the BID world.
     Figures come from config/projections.php and the model, never typed here; the fee sentences follow
     EmployerPlatformFee::status() and BidProgramFee::status(). Dollar figures stay next to "Projection, not results" and the
     #assumptions link (PublicClaimsTest). Same summary as the README's Business model section. --}}
@php
    use App\Support\BidProgramFee;
    use App\Support\EmployerPlatformFee;
    use App\Support\Projections\Figures;

    $assumed = fn (string $key, string $case = 'base') => config("projections.assumptions.{$key}.{$case}");
    $fee = fn (string $case) => Figures::exact($assumed('employer_platform_fee', $case));
    $gt = $model->run('a', 'base')->breakEvenMonth();
    $dc = $model->run('b', 'base')->breakEvenMonth();
    $tiers = config('projections.bid_fee_tiers');
    $linkClass = 'font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover';
@endphp
<section aria-labelledby="makes-money-heading" class="rule-heavy mt-10 bg-band px-5 pb-6 sm:px-6" data-how-it-makes-money>
    <x-station-sign heading-id="makes-money-heading">How Local Goods makes money</x-station-sign>
    <div class="mt-4">@include('projections.partials.label')</div>
    <ul class="mt-4 max-w-[52rem] space-y-3">
        <li>
            <span class="font-semibold">Employers pay a monthly fee for each participating employee.</span>
            That is an employee who received credits the month before. The fee is added once a month to the employer's first
            credit purchase, on top of the credits. In the base case it is {{ $fee('base') }} (range {{ $fee('low') }} to {{ $fee('high') }}).
            The credits themselves all go to local merchants.
            <span data-employer-fee-status>The employer platform fee is {{ EmployerPlatformFee::status() }}</span>
            (<a href="https://github.com/finnbutler1/local-goods/blob/main/docs/adr/0042-employer-platform-fee-switched-off.md" class="{{ $linkClass }}">ADR 0042</a>).
        </li>
        <li>
            <span class="font-semibold">The fee is Local Goods' own operating money.</span>
            It is kept separate from customer funds on the ledger and moved to the separate Local Goods Operating account by an
            operating sweep (ADR 0034). Customer money is never used for anything else.
        </li>
        <li>
            <span class="font-semibold">Merchants pay nothing during the pilot.</span>
            A merchant fee is built and set to 0. Later, a small fee per sale
            ({{ Figures::percent($assumed('merchant_fee_rate'), 1) }} in the base-case projection) would come only once the program shows it
            brings merchants new sales: independent merchants run on thin margins and already pay card processing.
        </li>
        <li data-bid-program-fee>
            <span class="font-semibold">{{ BidProgramFee::charges() ? 'Districts pay an annual program fee.' : 'Districts would pay an annual program fee.' }}</span>
            It is set by district size: {{ collect($tiers)->map(fn ($t) => $t['name'].' ('.$t['businesses'].') '.Figures::exact($t['fee']).' a year')->join('; ', '; and ') }}.
            Every district pays from its program start date, with no free pilot year. The Golden Triangle, with nearly 6,000 businesses,
            is Large; the model has no size for other districts, so it uses Medium for each of them, paid from the month they launch.
            <span data-bid-program-fee-status>The BID program fee is {{ BidProgramFee::status() }}</span>
            (<a href="https://github.com/finnbutler1/local-goods/blob/main/docs/adr/0044-bid-program-fee-charged-no-pilot-year.md" class="{{ $linkClass }}">ADR 0044</a>).
            The business improvement district (BID) would receive a share of the employer fee
            (planned; no money goes to a BID today).
        </li>
        <li data-unspent-credits>
            <span class="font-semibold">Unspent credits are never revenue.</span>
            In the product they don't expire: credits an employer hasn't given out yet can be refunded if the employer asks, and credits
            given to an employee stay in their wallet. The projection shows an estimate of credits never spent
            (about {{ Figures::percent($assumed('unspent_never_spent_share'), 0) }} of credits issued) next to revenue, but never counts it in revenue,
            profit or the break-even month. Depending on state law, unspent balances may belong to the employer, the employee or the state
            (unclaimed property), so Local Goods doesn't rely on them. Local Goods charges no dormancy fees.
        </li>
        <li>
            <span class="font-semibold">{{ $gt === null ? 'One district alone does not break even in the base case.' : 'Break-even in the base case.' }}</span>
            @if ($gt === null)
                Within {{ config('projections.break_even_search_months') }} months the model never breaks even for one district alone;
            @else
                One district alone breaks even around month {{ $gt }};
            @endif
            DC-wide it {{ $dc === null ? 'does not break even in that time' : "breaks even around month {$dc}" }}.
        </li>
    </ul>
    <p class="mt-4 max-w-[52rem] text-sm text-muted">
        Every figure here is a projection, worked out from the <a href="#assumptions" class="{{ $linkClass }}">stated assumptions</a> below, not a result.
    </p>
</section>
