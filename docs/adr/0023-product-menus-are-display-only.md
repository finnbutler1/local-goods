# 0023. Product menus for the demo merchants are display only

**Status:** Accepted, implemented.

## Context

Employees redeemed by typing a bare dollar amount. That is quick, but the
merchant, the employer and the BID admin saw only "$15.75", not what it
was for, and typing an amount at the counter is easy to get wrong. A short
menu per merchant makes the demo feel like a real purchase.

Redemption Fraud Detection's prompt (`8d5e1e70`), signals, model and eval
data are frozen: changing what the AI sees needs a fresh unseen holdout
and Finn's say-so.

## Decision

- Each of the 8 fictional demo merchants has a 4–8 product menu
  (`merchant_products`, seeded from `DatabaseSeeder::DEMO_MENUS`). Only
  names in `DatabaseSeeder::DEMO_MERCHANTS` that are not real candidates
  may have products (`Merchant::mayHaveProducts`, enforced on save). No
  gift cards: they would be cash.
- The employee picks a merchant, then quantities, with an optional "Other
  amount". The server works out the total from the menu's own prices
  (`App\Services\RedemptionBasket`); the browser's running total is only a
  preview. **The redemption amount is the total**, and all money logic
  uses that one number as before.
- **Display only.** `RedemptionService::redeem` scores the amount exactly
  as before, then stores the items (`redemption_items`) with the
  redemption. Nothing about products reaches `SignalCalculator` or the AI;
  a test checks the AI's input is byte-identical with and without items.
- Items copy the product's name and price at the time, so editing a menu
  never changes history. An "Other amount" next to products is its own
  line, so the lines always add up to the amount. A bare amount has no
  lines, exactly as before (seeded history stays bare).
- The merchant can view and edit their own menu at `/merchant/menu`
  (name, price, description, on/off the menu, add one; at most 12).
  Throttled, own products only, judges read-only. Reset demo restores the
  seeded menus; `demo:seed-if-empty` adds them to a demo seeded before
  them.

## Consequences

- One additive migration (two new tables). Snapshot before deploying.
- Items show on the wallet, the merchant dashboard, Fraud review (marked
  "not sent to Redemption Fraud Detection") and the employer's
  returned-credit rows, employees by initials as before.
- Products don't help catch fraud yet. Using them (for example a basket
  that doesn't fit the merchant) would be a new signal: a new prompt
  version and a fresh holdout.
