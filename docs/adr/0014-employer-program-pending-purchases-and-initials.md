# 0014. Employer program, purchases locked until paid, and initials only

**Status:** Accepted, implemented (payment simulated until Stripe test mode).
The separate request and pay steps were replaced by one-step Buy credits in
[ADR 0021](0021-one-step-buy-credits-and-a-platform-fee-account.md).

## Context

The employer/HR dashboard, merchant dashboard and BID participation view make
the money loop visible: buy credits, give them to employees, spend them,
merchants owed and paid. There is no payment processor yet, and these pages
show employee activity to people who aren't the employee.

## Decision

- **Program settings live on the employer:** a monthly budget (empty = no
  cap), the monthly allowance, and three optional occasions (new-hire
  welcome, birthday, recognition award; empty = off). The budget caps what
  can be given out per calendar month in DC time, across allowances and
  occasions. Each allocation records its occasion.
- **A purchase is locked until paid.** `CreditLedger::requestPurchase` writes
  only a `pending` row in `credit_purchases` and **no ledger entry**, so the
  credits can't be given out or spent. "Pay now (simulated, no real money)"
  calls `payPurchase`, the only code that writes that purchase's ledger
  entry. It flips the row only while it is still pending, so a double click
  writes one entry. With Stripe test mode, the button becomes a real test
  payment. Existing and seeded purchases count as paid (`simulated`).
- **Giving credits is all or nothing.** `allocateMany` checks the total
  against the paid balance and the remaining budget before writing anything.
- **Initials only outside the employee's own wallet.** Employer, merchant,
  BID admin and judge pages show "H.M." or "H.M. · #014" (initials and staff
  number), never a full name. HR keeps names in its own systems.
  `EmployeeNamePrivacyTest` opens every page as every role and fails if any
  seeded name appears.
- **The merchant login is linked from the merchant row** (`merchants.user_id`),
  like `employees.user_id`, so Reset demo can rebuild merchants and relink.

## Consequences

- The ledger invariant counts paid purchases only: purchases paid − payouts −
  amounts under review = the sum of all entries.
- The migration only adds empty or defaulted columns;
  `ProgramMigrationSafetyTest` checks every balance is unchanged.
- Employer metrics count cleared redemptions only, so a held redemption shows
  up in "waiting for review", never in "spent locally".
