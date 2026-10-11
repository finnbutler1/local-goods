# 0005. SQLite now, Amazon RDS for production next

**Status:** Accepted. SQLite is in use; RDS is planned.

## Context

The MVP needs a database for the ledger, redemptions and seeded demo data. A
managed database adds cost, networking setup and credentials. The demo
runs on one Elastic Beanstalk instance and resets its data on every deploy.

## Decision

Use **SQLite** for now:

- The file lives at `storage/database.sqlite` locally and on Beanstalk.
- Tests use an in-memory SQLite database.
- WAL mode, a busy timeout and immediate transactions avoid "database is
  locked" errors
  ([`SqliteConcurrencyTest`](../../tests/Feature/SqliteConcurrencyTest.php)).

Move to **Amazon RDS** (MySQL or PostgreSQL) before handling real employers
or real money.

## Consequences

- No database server to run or pay for, and setup is trivial for anyone
  cloning the repo.
- Data lives on the instance's disk and is wiped by the postdeploy reseed.
  That is acceptable for a demo and unacceptable in production.
- SQLite does not work across multiple instances, so scaling out requires RDS.
- Laravel's database layer means switching is mostly a config change plus
  testing migrations against the new engine.
