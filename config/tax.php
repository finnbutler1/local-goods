<?php

/*
 * DC sales tax shown at redemption (ADR 0027). For display and receipts
 * only: the merchant files and remits DC sales tax, never Local Goods, and
 * none of this is tax advice.
 *
 * Rates are in basis points (hundredths of a percent: 10% is 1000), integer
 * only. Each category keeps every rate it has had, oldest first, with the
 * date (DC time) it took effect, so a future change is a new row and the
 * rate used is always the one in effect at the moment of redemption. The
 * rate is snapshotted onto each redemption, so editing this file never
 * rewrites history.
 *
 * Verified on 2026-10-24 against the DC Office of Tax and Revenue (OTR) and
 * the DC Code:
 * - https://otr.cfo.dc.gov/page/sales-and-use-tax-faqs
 *   "Food, Drink prepared for immediate consumption" 10%; general rate 6%;
 *   "The general sales tax rate remains 6% through September 30, 2027."
 * - https://otr.cfo.dc.gov/release/notice-oct-1-2025-tax-changes
 *   Banner: "The increase in the general sales tax rate to 7% has been
 *   postponed. The general sales tax rate remains 6% through September 30,
 *   2027." The older body text of the same notice still says 7% from
 *   Oct 1, 2026; we follow the newer banner and FAQ. Re-check before
 *   2027-10-01.
 * - https://otr.cfo.dc.gov/page/taxable-and-non-taxable-services
 *   "health-club services" are taxed at 6% (so fitness is general).
 * - https://code.dccouncil.gov/us/dc/council/code/sections/47-2001
 *   (n)(2)(E) excludes SNAP "eligible foods" (groceries) and (n)(2)(B)
 *   excludes "personal service transactions" (so salons default to exempt;
 *   no OTR page says so directly).
 * Not modelled: the 8% soft drink rate and the rental vehicle, off-premises
 * alcohol, lodging and parking rates. No demo menu sells those.
 */
return [
    'verified_on' => '2026-10-24',

    'timezone' => 'America/New_York',

    'sources' => [
        ['url' => 'https://otr.cfo.dc.gov/page/sales-and-use-tax-faqs', 'note' => 'OTR Sales and Use Tax FAQs: the current rates, including 10% on food and drink prepared for immediate consumption and the 6% general rate.'],
        ['url' => 'https://otr.cfo.dc.gov/release/notice-oct-1-2025-tax-changes', 'note' => 'OTR notice: the increase to 7% is postponed and the general rate remains 6% through September 30, 2027.'],
        ['url' => 'https://otr.cfo.dc.gov/page/taxable-and-non-taxable-services', 'note' => 'OTR: taxable and non-taxable services, including health-club services at 6%.'],
        ['url' => 'https://code.dccouncil.gov/us/dc/council/code/sections/47-2001', 'note' => 'DC Code § 47-2001: groceries (SNAP eligible foods) and personal service transactions are not retail sales.'],
    ],

    'categories' => [
        'prepared_food' => [
            'label' => 'DC meals tax',
            'name' => 'Prepared food and drinks',
            'description' => 'Restaurant meals, coffee drinks and other food or drink prepared to eat now.',
            'rates' => [
                ['effective_from' => '2025-10-01', 'rate_bps' => 1000],
            ],
        ],
        'general' => [
            'label' => 'DC sales tax',
            'name' => 'General merchandise and taxable services',
            'description' => 'Goods such as books and flowers, and taxable services such as health clubs.',
            'rates' => [
                ['effective_from' => '2025-10-01', 'rate_bps' => 600],
                ['effective_from' => '2027-10-01', 'rate_bps' => 700],
            ],
        ],
        'exempt' => [
            'label' => 'Not taxed',
            'name' => 'Not taxed',
            'description' => 'For example groceries for home use and most personal services.',
            'rates' => [
                ['effective_from' => '2025-10-01', 'rate_bps' => 0],
            ],
        ],
    ],

    // The category a new product starts with, by the merchant's category.
    // A starting point only: the merchant confirms it with their accountant.
    'merchant_category_defaults' => [
        'cafe' => 'prepared_food',
        'restaurant' => 'prepared_food',
        'retail' => 'general',
        'fitness' => 'general',
        'salon' => 'exempt',
    ],

    'fallback_category' => 'general',
];
