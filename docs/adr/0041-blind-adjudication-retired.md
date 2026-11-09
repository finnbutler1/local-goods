# 0041. The blind adjudication is retired with no votes

**Status:** Accepted. Code and tests done. No migration, money, ledger, fraud
prompt, AI input, eval data, holdout, lock or sign-in change. Retires ADR 0011,
0019 and 0020.

## Context

Blind adjudication asked BID staff, contest judges and personal contacts to
rate the eval's disagreement cases without seeing the label or the AI's call.
Finn checked the live admin page on 2026-11-09: no votes had been submitted. It
made the judge experience heavier and added nothing.

## Decision

- A code setting, `reviewers.adjudication_retired`, closes voting. It has no
  environment override. The Close voting button was not used because it writes
  to the adjudication data, which stays exactly as it is.
- `AdjudicationSet::isOpen()` and `isClosed()` read the setting, so a set
  stored as published behaves as closed.
- It is removed from the judge home, the guided tour and the eval pages. Every
  disagreement on the eval pages is now visible to everyone.
- Old voting links show a "Voting has closed" page. Old voting forms write
  nothing and land on it. Personal contacts land there too; their links and
  tokens are unchanged.
- The platform admin can still read everything at `/admin/adjudication`, which
  is read-only.
- Tables, data, routes and the voting code stay. Its tests run with the setting
  switched off.

## Consequences

The evaluation results stand on one person's hand labels, and TRANSPARENCY.md
says so. Agreement shows how closely the AI matches that person, not whether
either is right.
