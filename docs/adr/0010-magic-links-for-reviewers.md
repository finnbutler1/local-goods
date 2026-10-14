# 0010. Magic links for reviewers, and one public judge account

**Status:** Accepted, implemented. Supersedes the invite links in
[ADR 0008](0008-read-only-reviewers-with-single-use-invite-links.md); the rest
of 0008 (judge role, read-only enforcement, view as) still stands.

## Context

The contest's rule (from Professor Yoest): judges never create accounts or set
passwords. ADR 0008 had reviewers open a single-use link and choose a password.
We also want one link we can publish in the README for any judge, without
losing attribution for the reviewers we invite by name.

## Decision

- **A personal magic link signs a reviewer straight in.** The platform admin
  makes it on `/admin/reviewers` and copies it; there is no password step and
  no account setup. It works as often as needed until `JUDGING_ENDS_AT`
  (default 2026-11-30 23:59, Washington DC time), checked on every use, so
  moving the date also moves links already sent.
- **The token never reaches a log.** A link looks like
  `/judge-access#<user id>.<token>`. Browsers don't send the part after `#` to
  the server, so it isn't in nginx, CloudFront or Laravel logs, Referer
  headers, or link previews. The page moves it into a form, clears it from the
  address bar and posts it. The 48-character random token replaces a URL
  signature.
- **Only a SHA-256 hash is stored**, compared with `hash_equals`. Any failure
  (ended, revoked, replaced, tampered, unknown) shows the same "This link has
  ended" page, so it never hints at which part was wrong. The route is
  rate-limited per IP (10 tries a minute).
- **A link can only open a judge account.** `ReviewerLinks::newLink` refuses
  any other role, and the sign-in checks the role again, so even a planted hash
  on an admin, BID admin, employer, employee or merchant account is refused.
  Judges can't log in with a password at all.
- **New link and Revoke end sessions, not just links.** The session stores a
  fingerprint of the link it came from. `EndReviewerSessions` ends a judge's
  session on its next request if it is revoked, judging has ended, or the
  fingerprint no longer matches. This works with production's cookie sessions,
  where there are no session rows to delete.
- **One shared "Public judge access" reviewer** (`reviewer_type = public`) for
  the README link: a generic read-only judge account created by
  `demo:seed-accounts`, found by type so there is only ever one, shown as its
  own type in the feedback inbox. Its feedback is limited to 3 a minute and 20
  a day per IP (others: 10 a minute). Like every judge it can't approve or
  reject a redemption. Named reviewers (for example Professor Yoest) keep
  personal links so their feedback is attributed.
- Old invite tokens were cleared by a migration; old `/invite/…` URLs show the
  ended page.

## Consequences

- A link is shown once, when made: only its hash is kept. A lost link means
  "New link", which ends the old one.
- Anyone who has a link can use it until judging ends, so a forwarded link
  works. That is the trade for no passwords; it only ever opens a read-only
  view of synthetic data, and Revoke or New link ends it at once.
- Signing in needs JavaScript (to read the part after `#`); the page says so if
  it is off.
- Making a new public link breaks the README link until the README is updated.
