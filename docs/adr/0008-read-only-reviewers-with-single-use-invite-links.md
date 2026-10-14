# 0008. Read-only reviewers with single-use invite links

**Status:** Partly superseded by [ADR 0010](0010-magic-links-for-reviewers.md):
reviewers now sign in with reusable magic links and never set a password. The
judge role, read-only enforcement and view as below still apply.

## Context

Contest judges, HR managers, merchants, BID staff and friends need their own
logins so they can try the app and leave feedback inside it. They must be able
to see every screen but never change data, and there is no mail service, so
invites can't be emailed.

## Decision

- **Every reviewer has the judge role**, plus a `reviewer_type` (contest judge,
  employer HR, merchant, BID, friend) used only to sort feedback.
- **Invites are signed, single-use links.** The platform admin creates the
  account and copies a link (`URL::temporarySignedRoute`, 7 days) to send
  themselves. Only a SHA-256 hash of the link's random token is stored, and it
  is cleared when the reviewer sets their password, so a link works once. No
  password is ever shown, stored in plain text or emailed.
- **Read-only is enforced twice.** `BlockJudgeWrites` runs on every web route
  and refuses any judge request that could change data, except a short
  allowlist (log out, their own feedback, their password). Gates and policies
  (`reset-demo`, `redeem`, `review`, `manage-*`, `FeedbackPolicy`) check again
  in the controllers. `JudgeReadOnlyTest` walks the whole route list, so a new
  write route is covered without anyone remembering to add a test.
- **View-as is read-only browsing, not impersonation.** A judge can open BID
  admin, employer, employee and merchant pages (GET only) and sees that role's
  menu plus a banner; they stay signed in as themselves. Platform-admin pages
  stay closed to judges because they hold real reviewers' names and emails.
- **Revoking** sets `revoked_at`, clears the token, deletes the reviewer's
  sessions, and blocks login; a middleware also logs out any revoked user on
  their next request.

## Consequences

- Reviewers see only synthetic demo data.
- A lost link is replaced with "New link", which kills the old one.
- Until production has HTTPS, invite links and passwords travel unencrypted.
  Turning on HTTPS is the next infrastructure step before sending links to
  people outside the team.
