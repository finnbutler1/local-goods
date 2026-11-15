{{-- How credits are treated for payroll taxes, for employers. General information only, never
     tax advice: no rates, no figures, no conclusions beyond these lines. The heading is the
     includer's. $reportHref (optional) links "the payroll report"; without it, plain words. --}}
@php
    $link = 'font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary';
    $reportHref ??= null;
@endphp
<ul class="mt-3 max-w-3xl list-disc space-y-2 pl-5 text-sm" data-employer-taxes>
    <li>
        Credits given to employees are generally treated as taxable wages: the IRS doesn't treat cash or cash equivalents,
        like gift cards or credits, as de minimis benefits that can be left out of wages. See
        <a href="https://www.irs.gov/publications/p15b" class="{{ $link }}" rel="noopener">IRS Publication 15-B, Employer's Tax Guide to Fringe Benefits</a>.
    </li>
    <li>Buying credits is generally a business expense for the employer.</li>
    <li>
        Use the
        @if ($reportHref)<a href="{{ $reportHref }}" class="{{ $link }}">payroll report</a>@else payroll report on the employer dashboard @endif
        to add the credits each employee received to payroll.
    </li>
    <li class="font-semibold">Local Goods doesn't give tax advice; check with your payroll provider or tax advisor.</li>
</ul>
