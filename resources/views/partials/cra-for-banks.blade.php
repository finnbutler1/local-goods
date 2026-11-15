{{-- For banks: the Community Reinvestment Act. General information only: it never says Local
     Goods qualifies, never describes the district's income level, and leaves the answer to the
     bank's regulator and CRA officer. The heading (or <summary>) is the includer's. --}}
@php
    $link = 'font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary';
@endphp
<ul class="mt-3 max-w-3xl list-disc space-y-2 pl-5 text-sm" data-cra-for-banks>
    <li>
        The Community Reinvestment Act (CRA) encourages banks to meet credit needs in low- and moderate-income communities,
        including support for small businesses.
    </li>
    <li>
        Funding a Local Goods program in a qualifying low- or moderate-income area may support a bank's community development
        goals. Buying credits for the bank's own staff generally wouldn't on its own.
    </li>
    <li>
        Whether an activity counts is decided by the bank's regulator, and the CRA rules are being revised: see the
        <a href="https://www.federalregister.gov/documents/2026/08/12/2026-16454/community-reinvestment-act-regulations" class="{{ $link }}" rel="noopener">current Federal Register notice on the Community Reinvestment Act regulations</a>.
    </li>
    <li class="font-semibold">Check with your CRA officer.</li>
</ul>
