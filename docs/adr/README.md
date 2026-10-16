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
| [0011](0011-blind-adjudication-of-eval-disagreements.md) | Blind adjudication of eval disagreements by BID staff and contest judges | Accepted, implemented |
| [0012](0012-one-ledger-three-account-kinds.md) | One ledger with three kinds of account: employee, employer, merchant | Accepted, implemented |
| [0013](0013-merchant-eligibility-matching.md) | Merchant Eligibility Matching: an AI suggestion, a person's decision, measured against locked labels | Accepted, implemented |
| [0014](0014-employer-program-pending-purchases-and-initials.md) | Employer program, purchases locked until paid, and initials only outside an employee's own wallet | Accepted, implemented |
| [0015](0015-stripe-connect-test-mode-separate-charges-and-transfers.md) | Stripe Connect in test mode: separate charges and transfers, Stripe-funded payouts only | Accepted, implemented |
