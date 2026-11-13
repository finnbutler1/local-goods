{{-- "How Local Goods makes money" (ADR 0030, ADR 0042): plain words for someone outside the BID world.
     Figures come from config/projections.php and the model, never typed here; the fee sentence follows
     EmployerPlatformFee::status(). Dollar figures stay next to "Projection, not results" and the
     #assumptions link (PublicClaimsTest). Same summary as the README's Business model section. --}}
@php
    use App\Support\EmployerPlatformFee;
    use App\Support\Projections\Figures;

    $assumed = fn (string $key, string $case = 'base') => config("projections.assumptions.{$key}.{$case}");
    $fee = fn (string $case) => Figures::exact($assumed('employer_platform_fee', $case));
    $gt = $model->run('a', 'base')->breakEvenMonth();
    $dc = $model->run('b', 'base')->breakEvenMonth();
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
            A merchant fee is built and set to 0. The base-case projection assumes a small one later
            ({{ Figures::percent($assumed('merchant_fee_rate'), 1) }} of sales), to be set after asking merchants what is fair.
        </li>
        <li>
            <span class="font-semibold">Districts pay no upfront fee during the pilot.</span>
            The projection assumes a one-time start-up fee of {{ Figures::exact($assumed('bid_initiation_fee')) }} for each new district later.
            The business improvement district (BID) would receive a share of the employer fee (planned; no money goes to a BID today).
        </li>
        <li>
            <span class="font-semibold">Unspent credits are never revenue.</span>
            They stay the employer's money and are refunded on request.
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
