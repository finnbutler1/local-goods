<?php

/*
 * The local recirculation estimate on the BID impact page (ADR 0017). An
 * outside study's rate, never a Local Goods measurement: the page labels it
 * "Estimate from an outside study, not measured by Local Goods". Change the
 * source only together with its numbers.
 */
return [
    'recirculation' => [
        // Share of revenue independents recirculated locally, retailers and restaurants combined.
        'independent_rate' => 0.523,
        'chain_rate' => 0.158,
        'publisher' => 'Civic Economics',
        'title' => 'Indie Impact Study Series: National Summary Report',
        'partner' => 'the American Booksellers Association',
        'data_years' => '2011–2012',
        'sample' => '134 businesses in 10 U.S. communities, none in DC',
        'url' => 'https://www.publishersweekly.com/pw/by-topic/industry-news/bookselling/article/55547-news-from-aba-growing-shopping-local.html',
    ],
];
