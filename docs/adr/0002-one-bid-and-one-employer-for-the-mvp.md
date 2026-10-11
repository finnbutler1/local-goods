# 0002. One BID and one employer for the MVP

**Status:** Accepted

## Context

The product could eventually serve many Business Improvement Districts, each
with many employers. Building for many BIDs from day one would add tenant
switching, per-BID settings, permissions and a more complex data model before
we know whether one district works.

## Decision

The MVP serves **one BID, the Golden Triangle in Washington, DC, and one
employer**. There is no BID selector, no tenant switching and no multi-BID data
model. The `bids` table exists so the BID has a name and boundary, but the app
assumes a single row.

## Consequences

- All the effort goes into making one complete loop work well: the employer
  funds credits, the employee redeems, the AI checks the redemption and the
  BID reviews it.
- The demo is easy to follow for judges and for a real BID.
- Expanding to more BIDs later will need a migration and scoping on every
  query. We accept that cost for the speed it buys now.
- Employer and merchant data in the demo is synthetic and fictional.
