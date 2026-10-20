# 0011. Blind adjudication of eval disagreements

**Status:** Accepted, implemented. Voting in batches of 10 and the minimum of
3 votes per case: [ADR 0020](0020-adjudication-in-balanced-batches-of-10.md).

## Context

The fraud eval measures the AI against one person's labels (Finn's). A contest
judge pointed out that where Finn and the AI disagree, Finn's label may be the
wrong one, and that a blind vote by a third party, such as BID staff, is what
makes the numbers credible. Judges are otherwise read-only
([ADR 0008](0008-read-only-reviewers-with-single-use-invite-links.md)), sign
in only with a personal magic link, and one shared "Public judge access"
account exists for the README link
([ADR 0010](0010-magic-links-for-reviewers.md)).

## Decision

- **The set.** The platform admin builds it on `/admin/adjudication` from the
  latest eval run: every case where Finn's label and the AI's call disagree, in
  every split, plus the same number of randomly chosen agreed cases as **blind
  controls**. Finn can add or remove cases in the draft. Publishing **locks**
  it (the model refuses edits); a change needs a new version, and publishing a
  new version closes the old one. A copy of each case's facts and labels is
  kept with the set, so a later eval run can't change a published set.
- **Who votes.** Only judge accounts with reviewer type **BID** or **contest
  judge**, not revoked, signed in with their own magic link. The public judge
  account, friends, employer HR and merchant reviewers can open the page but
  not vote; staff accounts, including Finn's, can never vote.
- **Blind.** One case at a time, in an order that is random per reviewer and
  stable across visits. Only the facts: merchant, category, amount, local time,
  balance before, visits in 7 and 30 days, past redemptions, median past amount
  and the history sentence. Never Finn's label or note, the AI's decision, risk
  score or explanation, the case id, the split, whether it is disputed, or
  anyone else's vote. Each vote is normal or suspicious with a required reason;
  it can change until **Submit all**, then it is locked.
- **One opening question:** "Have you looked at the Local Goods eval results
  page before today?" Results are reported for all adjudicators and for only
  those who answered no. While voting is open, eligible reviewers who haven't
  submitted don't see the per-case disagreements, pattern notes or
  adjudication results on `/eval` and `/bid-admin/eval`.
- **Read-only stays read-only.** `adjudication.start`, `adjudication.vote` and
  `adjudication.submit` join `BlockJudgeWrites::ALLOWED_ROUTES`, and each
  checks the `adjudicate` gate again. `JudgeReadOnlyTest` still tries every
  other write route as a judge.
- **Results.** Only submitted votes count. On each disputed case the majority
  sides with the AI or with Finn; a tie is "split". Summary: "On N disputed
  cases, adjudicators sided with the AI in X, with Finn in Y, split in Z."
  Control cases give a reliability check (how often votes matched the shared
  label), with a 95% Wilson interval. Also broken down by split and pattern
  tag. The admin page shows names, every vote and reason at any time. `/eval`
  shows the summary once someone has submitted, and each case's votes, without
  names, only after Finn closes voting, so later adjudicators can't see
  earlier votes.
- **Never changes the eval.** Labels, the lock file and the headline are not
  touched; adjudication is its own section.

## Consequences

- The eval can now say whether the "misses" are really the AI's mistakes.
- A logged-out visitor can still see the disagreements on `/eval`, so an
  adjudicator could look them up in a private window. The opening question
  and the separate "hadn't seen it" summary make that visible rather than
  hidden.
- A handful of adjudicators is a small sample; the page always says how many
  voted.
- Adjudication tables are not in `DemoResetter::DEMO_TABLES`, so Reset demo
  and deploys keep votes.
