# 0040. The four demo logins move to fictional organisations' addresses

**Status:** Accepted. Code and tests done; Finn runs the update on live by
hand. No migration, money, ledger, fraud prompt, AI input, eval data or lock
change.

## Context

The BID admin, employer admin, employee and merchant demo logins used
`@example.com`. Realistic addresses read better in the demo, but someone else
could own those domains, so the mail guard (`DemoMailGuard`) had to be live
first. `demo:seed-accounts` finds logins by email on every deploy, so changing
the config alone would create a second login for each role. Real people's
judge links must keep working throughout.

## Decision

- `config/demo.php` holds the new addresses and `previous_emails` (role → old
  address). Names don't change. Platform admin and judge are not in the list.
- Until a login has moved, `demo:seed-accounts` skips that role and says "run
  demo:update-emails", so a deploy creates no duplicates.
- `demo:update-emails` is a dry run unless given `--apply`. It matches by role
  and exact previous email, excludes judges and the platform admin in the query
  itself, needs the new address to be free, writes only `email` (not even
  `updated_at`), and does it in one transaction.
- Before and after, it compares each judge's link state using an 8-hex check
  of the stored hash (never the hash). Any difference rolls back and exits 1.
- If a new address is held by another account, that one login is left alone
  and reported; the others still move.

## Consequences

The old addresses keep working from the deploy until `--apply`, and stop
working after it. Tests: `UpdateDemoEmailsTest`. `previous_emails` can be
removed once the live update is done and no older database needs moving.
