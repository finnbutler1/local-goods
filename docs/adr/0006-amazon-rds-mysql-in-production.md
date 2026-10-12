# 0006. Amazon RDS MySQL in production, SQLite for development and tests

**Status:** Accepted, implemented. Supersedes [0005](0005-sqlite-now-rds-for-production-next.md).

## Context

The SQLite file lived on the Beanstalk instance's disk, and the postdeploy
hook ran `migrate:fresh --seed`, so every deploy wiped all data. That was
fine for a throwaway demo but not for anything that must keep its data.

## Decision

- **Production** uses the existing Amazon RDS MySQL database (`local_goods`).
  The connection comes from Beanstalk environment properties (`DB_CONNECTION`,
  `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). No
  credentials are in the repo.
- **Local development and the default test run** stay on SQLite. The WAL,
  busy-timeout and IMMEDIATE-transaction settings live only on the `sqlite`
  connection.
- **Deploys never wipe data.** The hook
  ([`01_database.sh`](../../.platform/hooks/predeploy/01_database.sh)) runs
  `migrate --force`, then `demo:seed-if-empty`, which seeds only when every
  demo table is empty.
- **The hook runs at predeploy**, for deploys and for configuration changes,
  before Beanstalk restarts PHP. It first checks that `DB_CONNECTION` is exactly
  `mysql` or `sqlite` and that the other DB_ properties are set. If anything
  fails, the deployment stops and the running site keeps its old settings.
  (A first attempt ran it at postdeploy; a typo in `DB_CONNECTION` then broke
  the database pages until a redeploy.) A unit test fails if a wipe command is ever added back.
- **Reset demo** clears only the six demo tables, never users, sessions or
  future tables.
- **Redemptions lock the employee row** (`SELECT ... FOR UPDATE`) so two
  simultaneous redemptions can't overspend on MySQL.
- **CI runs every push on both SQLite and MySQL 8.4.**

## Consequences

- Data survives deploys and instance replacement, and the app can scale past
  one instance.
- RDS costs money and needs its security group to allow the Beanstalk
  instance.
- On MySQL, ids keep counting up after Reset demo (on SQLite they restart at 1).
