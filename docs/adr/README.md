# Architecture decision records

Short records of the main design choices in Local Goods: what we decided, why,
and what follows from it. Each one is written so a reviewer can understand the
trade-off in under a minute.

| # | Decision | Status |
|---|---|---|
| [0001](0001-stripe-connect-instead-of-self-built-escrow.md) | Stripe Connect instead of self-built escrow | Accepted, not yet implemented |
| [0002](0002-one-bid-and-one-employer-for-the-mvp.md) | One BID and one employer for the MVP | Accepted |
| [0003](0003-fail-closed-fraud-detection.md) | Fail-closed fraud detection | Accepted, implemented |
| [0004](0004-elastic-beanstalk-instead-of-amplify.md) | Elastic Beanstalk instead of Amplify | Accepted, implemented |
| [0005](0005-sqlite-now-rds-for-production-next.md) | SQLite now, Amazon RDS for production next | Superseded by 0006 |
| [0006](0006-amazon-rds-mysql-in-production.md) | Amazon RDS MySQL in production, SQLite for development and tests | Accepted, implemented |
| [0007](0007-fortify-for-login-and-a-role-column.md) | Laravel Fortify for login, and one role column instead of a permissions package | Accepted, implemented |
