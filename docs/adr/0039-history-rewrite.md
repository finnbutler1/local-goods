# 0039. Names removed from git history with one partial rewrite

**Status:** Accepted, implemented (2026-11-07, approved by Finn on the day).
No migration, money, ledger, fraud prompt, AI input, eval data or holdout
change. One lock change: the merchant label lock was re-locked at the new
labeling commit with every row unchanged.

## Context

Merchant data is public business information only, with no owner names or
personal details (Prof. Yoest's rule). The names were removed from the code
on 2026-10-21 (`d5fdaf4`, `49d73a9`), but the repo is public and
older commits still held them: 4 people the AI named in its merchant
reasoning, 5 candidate businesses whose names are a person's, and one
personal detail (a café's founders described as veterans, 2 snippets).
`docs/history-rewrite-runbook.md` planned the removal; CLAUDE.md said never
to rewrite history, so Finn changed that rule for this one rewrite.

## Decision

- Replace each name and snippet with "[name removed]" in every commit, using
  `git filter-repo --replace-text` with the 11 lines the runbook's script
  builds (it never prints a name).
- **Rewrite only from `6b68968` on** (`--refs 6b68968^..main`), not the
  whole history. The first full rewrite, in a scratch clone, changed all 440
  commits: the repo's first three commits (2026-09-21 to 09-26) were made on
  GitHub's website and are signed by GitHub, and filter-repo always removes
  signatures. That changed the first commit's hash and so every hash after
  it, including the fraud eval lock's `5f594c1`. None of the 119 commits
  before `6b68968` contains a name or snippet (checked), so the partial
  rewrite keeps them byte-for-byte, signatures included.
- Delete every branch but `main` first (`redesign-a` and `design-pass`,
  both fully merged), so no old branch keeps the names reachable.
- Force-push from a fresh clone, with a lease pinned to the old `main`.

## Verification (2026-11-07)

- Before: 4 names, 2 anchored snippets, 5 candidates, 11 lines; 86 commits
  with people's names, 100 with candidate names, 0 in HEAD, 0 in commit
  messages.
- After, on a fresh clone of GitHub: 0 hits for every name and snippet
  across all 440 commits (also 0 for URL-encoded and other spaced forms of
  each name); exactly 321 commits changed; `5f594c1` and the 3 signed
  commits unchanged; HEAD's tree `7cc64d23c3a3…` and the labels blob
  `fe0e8a27bc46…` identical; full test suite passed on the rewritten tree.

## Old to new hashes

Every commit hash cited in the repo, plus the ends of the affected ranges.
`docs/history-rewrite-runbook.md` describes the old history and keeps the
old hashes.

| Old | New | What |
|---|---|---|
| `30b610e` | `37cc902` | Merchant labeling commit (label lock) |
| `5f594c1` | `5f594c1` | Fraud eval labeling commit: unchanged |
| `6b68968` | `2d585fb` | First commit with candidate names |
| `c3adfce` | `20431bf` | First commit with people's names |
| `9442899` | `a5b9638` | Last commit with people's names |
| `b181ab5` | `5f50bba` | Last commit with candidate names |
| `34fdc84` | `d5fdaf4` | Redaction of names in the AI's reasoning |
| `3e59269` | `49d73a9` | Removal of the 5 person-named candidates |
| `4349e39` | `120c9ca` | Accessibility fix (`docs/accessibility.md`) |
| `458f89d` | `2d4866a` | Accessibility fix |
| `4790ea7` | `b65c76c` | Accessibility fix |
| `7d64000` | `f8c0ff3` | Accessibility fix |
| `80e1157` | `1fb4507` | Accessibility fix |
| `83353e6` | `5159052` | Accessibility fix |
| `9064eb0` | `65bea6e` | Accessibility fix |
| `c1c27f5` | `5c5394e` | Accessibility fix |
| `e19f070` | `8a80cae` | Accessibility fix |
| `d8aa1ea` | `9c1a725` | MySQL test change (ADR 0028) |
| `69c904c` | `9e0c8c0` | Last deployed commit (`.last-deploy-commit`) |
| `7858230` | `a4c22d3` | `main` before the rewrite |

## Consequences

- Every commit from 2026-10-14 on has a new hash. Old GitHub Actions runs
  still show the old hashes (they record timing) and will stop resolving;
  they are left as they are.
- Anyone who cloned before keeps the old history (no forks are known).
- Old commits can stay reachable on GitHub by direct URL until GitHub
  Support purges them (runbook steps 8 and 10).
- "Fixed in commit" SHAs in the live feedback table are old hashes; Finn
  updates them in `/admin/feedback`.
- History is not rewritten again without Finn's explicit say-so.
