# 0031. Districts, partnerships and merchant joining

**Status:** Accepted, implemented.

## Context

Partners asked three questions:

- How does exclusivity work within a BID?
- How does a merchant join?
- Why would a BID need Local Goods rather than doing this itself?

Until now the answers were only talk. No merchant was tied to a place:
`RedemptionService::redeem` checked only the balance, so any `Merchant`
row could be paid. There was no partnership record, and merchants existed
only because the seeder made them.

This had to leave the rest alone:

- the fraud prompt (`8d5e1e70`), its signals, AI input, eval data, holdouts
  and locks;
- the eligibility prompt (`458e8c2d`), its eval data and locks.

`RedemptionService` could change only minimally, for the district check.

## Decision

### Districts

`districts` holds one row, the Golden Triangle BID.

- **Boundary.** DC's published polygon, copied by the migration from
  `data/sources/golden_triangle_bid.geojson`. This is the same file
  `eligibility:build-candidates` uses, so candidates and districts share
  one boundary.
- **Source.** DC GIS "Business Improvement Districts" (layer 15 of
  `DCGIS_DATA/Business_Betterment_Areas_WebMercator`). The dataset was
  edited on 2026-02-17 and downloaded on 2026-10-14. The row also stores
  the file's SHA-256 and a plain description.
- **No runtime fetch.** Nothing fetches the boundary while the site runs.
- **Point-in-polygon.** The existing `BidBoundary`, which now also refuses
  NaN, infinite and off-the-globe points.

Merchants gain `district_id`, `latitude`, `longitude`,
`district_approved_at` (and by whom) and `live_at`. Employers gain
`district_id`, the district their credit program runs in.

- **Backfill.** The migration places the 8 fictional demo merchants at a
  DC Master Address Repository (MAR) point on their block, approved and
  live. The same points are in `DatabaseSeeder::DEMO_COORDINATES`. Every
  existing employer is placed in the Golden Triangle.
- **Two moved addresses.** Two demo addresses named blocks just outside
  the boundary, so they moved to blocks inside it:
  - Connecticut Noodle Bar: 1700 → 1100 block of Connecticut Ave NW.
  - Lafayette Cycle Studio: 1500 → 1700 block of H St NW.

### The rules, in `DistrictRules`

1. **Approval only inside the boundary.** A merchant is approved for a
   district only if its point is inside. This is enforced in
   `approveMerchant` and in `Merchant`'s saving hook, so a direct write
   can't move an approved merchant outside either.
2. **Credits only at approved, live merchants in the program's district.**
   This applies when the employee's employer has a program district. The
   change to `RedemptionService::redeem` is one line, first thing, before
   scoring:

   ```php
   DistrictRules::ensureRedeemable($employee, $merchant);
   ```

   - **Pay codes.** `PayCodes` makes the same check before issuing a code
     and before claiming one. Otherwise `confirm` would report the refusal
     as "Not enough credits" and cancel the code as a balance failure.
   - **Wallet.** It lists only `Merchant::redeemableFor($employee)`.
   - **No program district.** An employer without one is not restricted.
     This keeps the existing redemption tests' own fixtures as they were;
     the seeded and live employers have a district.
3. **One active exclusive partnership per district.** The database
   enforces it: `partnerships.exclusive_active_key` is unique. The model
   sets it to the district id while a partnership is exclusive and
   active, and to NULL otherwise. Many NULLs are allowed on SQLite and
   MySQL.

### Partnerships

`partnerships` records, for each partnership:

- the BID and the district;
- whether it is exclusive;
- start and end dates;
- the revenue share in basis points (0 by default, at most 5000);
- the data terms;
- its status: draft, active or ended, forward only.

**Who changes it.** `PartnershipSettings` is the only writer: a BID
admin or the platform admin, with a reason, against the reviewed version.
(At first only the platform admin could; Finn widened it to the BID admin
on 2026-10-28, since the BID is a party to the agreement.) Each change is
one append-only `partnership_changes` row (who, when, why, before and
after).

**The page.** `/bid-admin/partnership` uses Review (writes nothing), then
Confirm (a ticked box), the same pattern as fees in ADR 0029. Judges read
it and see the revenue share in words only: they count as the public
(ADR 0029).

**Revenue share.** `PartnershipRevenueShare` multiplies the share rate by
the platform's own district ledger lines:

- merchant fees at district merchants, net of fees given back on refunds;
- platform fees on purchases by district-program employers.

It rounds half up and is display only: it writes nothing, and no money
goes to a BID. It is $0.00 while both fees are 0.

**Seed and Reset demo.** The seed adds one demo partnership, labelled
"Demo partnership — not an actual agreement." Partnerships point at
`bids`, which Reset demo clears, so they are demo tables.

### Addresses: DC's Master Address Repository

`DcMarGeocoder` reads a typed address ("1700 K St NW, Washington DC")
into MAR's form ("1700 K STREET NW"). It then queries the MAR address
points layer that DC GIS publishes.

- **Why that layer.** It is keyless. The older keyless MAR verifier
  (citizenatlas) has been retired, and MAR 2's API needs a key.
- **What is sent.** Only the address text: no cookies, no user details,
  and quotes are escaped.
- **Caching.** `AddressLookup` keeps each answer in `address_lookups`:
  - a found address is never asked again;
  - "not found" is asked again after 7 days;
  - "unavailable" is never kept and never counts as inside.
- **Checker privacy.** A lookup from the public checker keeps only the
  SHA-256 of the normalised address and the answer, never the typed text.
  `applications:purge` deletes these after 30 days. An application's own
  lookup keeps its query.
- **Shared decision.** `DistrictCheck` (address lookup, then
  `DistrictRules::isInside`) serves both the public checker and the
  application check.

### Joining

`/apply` is public and needs no account. It asks for business name,
address, category, contact email and "what makes you independent".

- **Before the form.** It opens with the prototype notice, requires "I
  understand this is a prototype", and has a privacy note: what is stored,
  that the name, address and category go to the AI, that nothing is shared
  or sold, and deletion on request or after 2026-12-31.
- **Spam.** A honeypot field stores nothing, and one IP may send 3
  applications in 10 minutes.
- **No outside calls.** Sending calls nothing outside: no AI, no address
  lookup, no email.

`MerchantApplications` is the only place an application's status changes:

    applied → eligibility_checked → bid_approved → stripe_onboarding → live
                                  ↘ declined (also from applied)

Each move is one `UPDATE … WHERE status = from` plus one append-only
`merchant_application_events` row (who, when, why), in one transaction.
No step is skipped and two presses can't both win.

- **Check.** A BID or platform admin presses Run check, which is throttled
  because it makes one AI call. It looks the address up, applies the
  boundary rule and asks Merchant Eligibility Matching for a suggestion.
  - The AI gets the name, address, category and an empty
    `business_activity`. It never gets the shop's own answer or the email.
  - The prompt is unchanged, and the result is never eval data.
  - Whatever the AI says, the status stops at `eligibility_checked`.
- **Approve.** Only from `eligibility_checked`, only when the address is
  inside: the stored answer and `ensureInside` on the stored point must
  both agree. It needs a reason the shop sees.
- **Decline.** From `applied` or `eligibility_checked`, with a reason the
  shop sees. It is final.
- **Onboard.** There is no email. The private status link
  (`/apply/status#<id>.<40 random characters>`) is shown once after
  applying.
  - Only its SHA-256 is stored, and the token stays after the `#`, so it
    never reaches a server log (the same pattern as reviewer links,
    ADR 0010).
  - Holding the link lets the applicant sign in after approval. Signing in
    creates a merchant login and the merchant itself, approved through
    `DistrictRules` but not live.
  - The applicant then uses the existing Stripe Express self-onboarding.
- **Live.** `StripeOnboarding::apply` (the return/sync page and the
  `account.updated` webhook) calls `goLiveIfReady`. Once Stripe says the
  account can receive transfers, `live_at` is set and the application is
  live. This is one line; nothing else in Stripe changed.
- **The queue.** `/bid-admin/applications` lists applications. Each one
  shows the facts, a small SVG map, the suggestion with its confidence and
  "Suggestion only: a BID admin decides". Judges read it with the email
  masked and buttons disabled.
- **Seed.** Two fictional sample applications, one approved and one
  waiting, labelled "Fictional sample application". Their suggestions are
  written by the seed and say so, and they have no merchant row.
- **Retention.** `applications:purge --before=YYYY-MM-DD` deletes
  non-sample applications made before the date, with their steps, plus
  checker lookups older than 30 days. It is run by hand on 2027-01-01, or
  on request.

### Pages

- **`/district`.** An inline SVG drawn from the stored polygon (no tiles),
  with live merchants as keyboard pins. The map is a labelled group
  because it holds links. It has a text alternative and a "Check an
  address" box that uses `DistrictCheck`.
- **`/who-does-what`.** Eight steps as tabs that are links without
  JavaScript. For each step: who handles it, what the software does, what
  the BID does and the real screen. Linked from the landing page.
- **`/bid-admin/impact.csv`.** The district's aggregate numbers, no names
  and no fees, so the data terms' "export at any time" is true.

## Consequences

- **Migrations.** Three additive migrations
  (`2026_10_30_000001…000003`), covered by `DistrictsMigrationSafetyTest`.
  Take the RDS snapshot "before-districts" before deploying.
- **Reset demo.** Partnerships and applications are demo tables, so Reset
  demo clears real applications too and restores the two samples.
  `demo:seed-if-empty` adds the partnership and samples to a demo seeded
  before them.
- **The status link is a key.** Anyone holding the link can start that
  shop's onboarding. The page says so.
- **Odd AI input.** The eligibility prompt describes license facts, but an
  application gives self-reported facts with no license activity.
  Suggestions for applications may be weaker than for candidates, and they
  were not measured.
- **Test fixture.** One merchant dashboard test places its test shop in
  the district. The judge read-only sweep gets an application to aim at.
  The Reset demo table list and the BID admin menu (now with More) were
  changed on purpose.
