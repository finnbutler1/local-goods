# 0024. Lead with the buy-local mission; the "roughly 3x" line only with its label and source

**Status:** Accepted, implemented. Replaces the "No multiplier claim" bullet of [0017](0017-bid-impact-from-the-ledger.md); the rest of 0017 stands.

## Context

The landing page, the judge home and the README explained what Local
Goods does ("What Local Goods is", "Spend local. Prove it.") but not why it
exists. People who work downtown follow a commute-desk-commute loop, and
their spending leaves the neighborhood with them. A judge, a BID director or
an HR manager should see that, and who gains, within seconds.

ADR 0017 banned "3x" and "multiplier" outright, because such figures are
often repeated without a checkable source. We already show one checkable
source: Civic Economics' Indie Impact national summary (with the American
Booksellers Association), where independents recirculated 52.3% of revenue
locally and chains 15.8%, about 3.3 times as much. Saying "roughly 3x" is a
fair plain-English reading of that figure, as long as it is never presented
as something Local Goods measured or causes.

## Decision

- **Mission first.** Headline "You work here. Spend here."; a short "the
  problem" block that names the commute-desk-commute loop without blaming
  employees; "Why it matters" with the district first, then employers,
  merchants, employees and the BID. The judge home's "What Local Goods is"
  becomes "Why Local Goods exists". Prototype, Stripe test mode, demo data
  and "the AI assists, people decide" stay stated.
- **The 3x line** is one sentence: "Money spent at independent local
  businesses tends to recirculate roughly 3x more in the local economy
  than money spent at chains, an effect often called the local
  multiplier." On the site it comes only from
  `resources/views/partials/recirculation-evidence.blade.php`, which always
  renders the label "Estimate from an outside study, not measured by Local
  Goods", the two rates and the ratio (from `config/impact.php`), "Real
  results will be measured in a pilot" and the source. It is used on the
  landing page (below the "Why it matters" cards, never in the hero) and on
  `/bid-admin/impact` (in the existing estimate box). The README carries
  the same sentence with the same label and source in "Why it matters".
- **Never** "Local Goods creates/delivers 3x", never "guaranteed", never a
  measured result.
- **No business-model numbers** (fees, revenue, interchange, margins) on any
  public page or in the README.

## Consequences

- `PublicClaimsTest` now checks, for every page a logged-out visitor or the
  public judge can open, and for every README and TRANSPARENCY section:
  any "3x", "three times" or "multiplier" comes with the label and the
  Civic Economics source; nothing says Local Goods creates the effect or
  guarantees anything; no fee, revenue or interchange figure appears; the
  old "What Local Goods is" heading is gone. The fraud signal "3x or more
  the usual amount" is still exempt.
- A test checks the configured rates still round to 3, so "roughly 3x"
  can't drift from the source.
