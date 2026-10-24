# 0027. DC sales tax shown at redemption

**Status:** Accepted, implemented.

## Context

Step 6 of the October 23 plan. An employee buying a $5.75 latte with
credits should see the DC tax before paying ("$5.75 + DC meals tax $0.58 =
$6.33"), the merchant needs the tax it collected to file it, and the
employer deserves one honest line on how credits are taxed for employees.
This touches money, so it had to leave the money rules alone: the ledger,
Stripe transfers, `TransferGate`, the webhook, reconciliation, the fraud
prompt (`8d5e1e70`), its signals, model and eval data.

## Decision

- **One number stays the money.** `redemptions.amount_cents` is the total
  the employee pays: menu subtotal plus tax. It is what Redemption Fraud
  Detection sees, what the ledger debits, what the merchant earns and what
  a Stripe test-mode transfer sends, exactly as before. Tax is a snapshot
  stored next to it, never a separate money movement.
- **Rates in config, by date.** `config/tax.php` holds each category's
  rates in basis points with the date (DC time) each took effect:
  prepared food 10%, general 6% (7% from 2027-10-01), exempt 0%. Sources
  and a verified-on date (2026-10-24) are in the file and TRANSPARENCY.md.
  A future change is a new row. An unknown category or a date before the
  first rate throws; nothing falls back to 0.
- **How tax is worked out** (`App\Support\SalesTax`): per category, on that
  category's subtotal, rounded half up to the cent, integer cents only.
  The rate is the one in effect at that moment.
- **Only menu items get tax added.** Each product has a `tax_category`,
  starting from its merchant's category (cafés and restaurants prepared
  food; shops and fitness general; salons exempt) and editable on the
  menu page with "Confirm this with your accountant." A typed-in amount,
  alone or as an "Other amount" next to items, is the shop's own total
  with any tax already in it: nothing is added, and it says "Amount
  includes any tax charged by the shop."
- **Snapshots** (`App\Support\TaxBreakdown`): every redemption and pay code
  stores `tax_basis` (`added`, `included`, or null for rows from before tax
  display), `subtotal_cents`, `tax_cents` and `tax_lines` (category, label,
  rate, taxable amount and tax). Each item stores its category and rate.
  Screens read only the snapshot, so a later config change never rewrites
  a receipt. Older rows show "Tax not itemized (before tax display)".
- **The one change to `RedemptionService`:** `redeem()` takes an optional
  `TaxBreakdown` (default: a typed-in amount, tax included). The existing
  check that items add up to the amount now checks items + tax, and the
  four tax columns are written in the same `Redemption::create`. Nothing
  else in it changed.
- **Pay codes** fix the tax when the code is made: the code's amount is the
  total including tax, the cashier's review shows subtotal, tax and total,
  and Confirm redeems with exactly that snapshot.
- **Screens:** the wallet shows each item's tax, then subtotal, tax lines
  and total before paying (both tabs), and on the confirmation, recent
  redemptions and the pay code. The merchant sees the breakdown on every
  redemption, "Tax collected" this month and last (cleared menu orders,
  "For your records. Your business files and remits DC sales tax; Local
  Goods does not."), and a CSV of redemptions with subtotal, tax and total.
  The employer sees one note with no numbers. BID admin, Fraud review and
  reconciliation are unchanged.
- **Demo:** seeded products get categories (Reset demo and the menu-only
  `demo:seed-if-empty` too); seeded typed-in history is marked tax
  included; three new seeded menu orders at Farragut Grind carry tax.
- **Migration** `2026_10_27_000001_add_sales_tax` only adds columns and
  backfills existing products' categories from their merchant. No money
  row is created or changed (`SalesTaxMigrationSafetyTest`).

## Consequences

- The AI now sees the total including tax for menu orders, so a basket
  scores a little higher than its menu prices. It never sees tax fields
  (`FraudInputTaxTest`). Its employee-median signal compares new taxed
  totals with older amounts; the difference is at most the 10% rate, and
  the prompt is frozen.
- Typed-in amounts aren't split into tax, so "Tax collected" covers menu
  orders only, and says so.
- Not tax advice. Rates are DC's published rates, shown for display; the
  merchant is responsible for filing and remitting, and the employer for
  how credits are treated for employees.
- Not modelled: DC's 8% soft drink rate and the rental vehicle,
  off-premises alcohol, lodging and parking rates. No demo menu needs them.
- A pay code made in the last ten minutes before a rate change keeps the
  rate it was made with.
