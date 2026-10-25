# Architecture decision records

Short records of the main design choices in Local Goods: what we decided, why,
and what follows from it. Each one is written so a reviewer can understand the
trade-off in under a minute.

| # | Decision | Status |
|---|---|---|
| [0001](0001-stripe-connect-instead-of-self-built-escrow.md) | Stripe Connect instead of self-built escrow | Accepted, implemented in test mode (0015) |
| [0002](0002-one-bid-and-one-employer-for-the-mvp.md) | One BID and one employer for the MVP | Accepted |
| [0003](0003-fail-closed-fraud-detection.md) | Fail-closed fraud detection | Accepted, implemented |
| [0004](0004-elastic-beanstalk-instead-of-amplify.md) | Elastic Beanstalk instead of Amplify | Accepted, implemented |
| [0005](0005-sqlite-now-rds-for-production-next.md) | SQLite now, Amazon RDS for production next | Superseded by 0006 |
| [0006](0006-amazon-rds-mysql-in-production.md) | Amazon RDS MySQL in production, SQLite for development and tests | Accepted, implemented |
| [0007](0007-fortify-for-login-and-a-role-column.md) | Laravel Fortify for login, and one role column instead of a permissions package | Accepted, implemented |
| [0008](0008-read-only-reviewers-with-single-use-invite-links.md) | Read-only reviewers with single-use invite links | Partly superseded by 0010 |
| [0009](0009-https-through-cloudfront.md) | HTTPS through CloudFront in front of Beanstalk | Accepted, implemented |
| [0010](0010-magic-links-for-reviewers.md) | Magic links for reviewers, and one public judge account | Accepted, implemented |
| [0011](0011-blind-adjudication-of-eval-disagreements.md) | Blind adjudication of eval disagreements by BID staff and contest judges | Accepted, implemented; batches in 0020 |
| [0012](0012-one-ledger-three-account-kinds.md) | One ledger with three kinds of account: employee, employer, merchant | Accepted, implemented; platform account added in 0021 |
| [0013](0013-merchant-eligibility-matching.md) | Merchant Eligibility Matching: an AI suggestion, a person's decision, measured against locked labels | Accepted, implemented |
| [0014](0014-employer-program-pending-purchases-and-initials.md) | Employer program, purchases locked until paid, and initials only outside an employee's own wallet | Accepted, implemented |
| [0015](0015-stripe-connect-test-mode-separate-charges-and-transfers.md) | Stripe Connect in test mode: separate charges and transfers, Stripe-funded payouts only | Accepted, implemented |
| [0016](0016-fraud-detection-gates-stripe-transfers.md) | Redemption Fraud Detection gates Stripe transfers: one gate, recorded human decisions, denied credit to the employer for suspected abuse | Accepted, implemented |
| [0017](0017-bid-impact-from-the-ledger.md) | BID impact numbers come from the ledger and roll up the HR dashboards; an outside study's recirculation rate shown only as a labelled estimate | Accepted, implemented |
| [0018](0018-only-cloudfront-reaches-beanstalk.md) | Only CloudFront reaches Beanstalk (with a kill switch); unsafe production settings stop a deploy | Accepted, implemented |
| [0019](0019-personal-contacts-vote-separately.md) | Personal contacts vote in the blind adjudication, reported separately and never in the headline | Accepted, implemented |
| [0020](0020-adjudication-in-balanced-batches-of-10.md) | Blind adjudication in balanced batches of 10, with vote counts and "too few votes to call" | Accepted, implemented |
| [0021](0021-one-step-buy-credits-and-a-platform-fee-account.md) | One-step Buy credits with embedded Checkout, and a platform fee (off) on its own platform account | Accepted, implemented |
| [0022](0022-platform-set-up-stripe-accounts-for-demo-merchants.md) | Platform-set-up Stripe test accounts for the fictional demo merchants with no login (Express can't be prefilled), honestly labelled | Accepted, implemented |
| [0023](0023-product-menus-are-display-only.md) | Product menus for the demo merchants: the amount is the total, items are display only and never sent to the fraud AI | Accepted, implemented |
| [0024](0024-mission-framing-and-the-labelled-3x-line.md) | Lead with the buy-local mission; "roughly 3x" only next to its outside-study label and source (replaces 0017's no-multiplier rule) | Accepted, implemented |
| [0025](0025-transit-lines-brand.md) | Transit lines brand and Logo A, light and dark tokens, visible button states, disclosure menus | Accepted, implemented |
| [0026](0026-pay-in-person-with-pay-codes.md) | Pay in person: one-time 6-character pay codes the merchant confirms, through the same RedemptionService and TransferGate; channel display only; held payments can be cancelled | Accepted, implemented |
| [0027](0027-dc-sales-tax-shown-at-redemption.md) | DC sales tax shown at redemption: rates by date in config, added on menu items only, snapshotted on each redemption; the amount stays the total the AI, ledger and Stripe use | Accepted, implemented |
| [0028](0028-refunds-and-corrections.md) | Refunds and corrections: requests, a platform admin decides with a reason, every fix an append-only ledger line backed by a Stripe test-mode reversal, refund or the existing payout path, confirmed by the webhook; reconciliation matches | Accepted, implemented |
| [0029](0029-merchant-fee-ready-but-at-zero.md) | A merchant fee on each sale, ready but at 0: an audited platform-admin setting, snapshotted per redemption, held back from the Stripe transfer, given back in proportion on refunds, reconciled; judges see no amounts | Accepted, implemented |
