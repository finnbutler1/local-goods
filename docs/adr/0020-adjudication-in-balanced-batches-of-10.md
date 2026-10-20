# 0020. Blind adjudication in balanced batches of 10

**Status:** Accepted, implemented.

## Context

Blind adjudication ([ADR 0011](0011-blind-adjudication-of-eval-disagreements.md))
asked each reviewer to vote on all 32 cases in the set (16 disputed, 16
controls), about 15 minutes. That is a lot to ask of busy BID staff, contest
judges and personal contacts ([ADR 0019](0019-personal-contacts-vote-separately.md)),
and someone who stopped halfway counted for nothing. Shorter turns get more
people to vote, but then each case gets fewer votes, and a case decided by
one or two votes says little.

## Decision

- **Batches of 10.** After the opening question ("Have you looked at the
  eval results page before today?", unchanged) each reviewer gets 10 cases:
  7 disputed and 3 controls while enough of each are left, otherwise filled
  up with the other kind. The batch is shown one case at a time, in an order
  that is random per person and stable across visits. What a case shows is
  unchanged: only the facts.
- **How cases are picked** (`AdjudicationAssigner`). Within each kind, the
  cases with the fewest votes so far in the reviewer's own group come first;
  ties are broken by a per-person hash. A case's count includes every time
  it was handed out in that group (submitted or still open) plus the votes
  of anyone who pressed Submit all before batches. BID and contest judges
  form one group and personal contacts another; neither affects the other's
  balance. A person is never given a case twice (a unique index on
  participant and case).
- **Stored** in a new table, `adjudication_assignments`: participant, case,
  batch number, position, and when the batch was submitted. This is the one
  migration; it only adds a table.
- **More is optional.** After submitting a batch: "Thanks! Want to do 10
  more?" with "Yes" (`adjudication.more`) or "No thanks, I'm done"
  (`adjudication.finish`). After the last cases (32 = 10 + 10 + 10 + 2) they
  are finished automatically. Both routes are on the judge and personal
  contact allowlists and check the `adjudicate` gate. A vote can only be
  cast on a case in the reviewer's open batch.
- **What counts.** A vote counts once its batch is submitted, so someone who
  stops after one batch still counts. Votes in an open, unsubmitted batch
  don't. While voting is open, the eval pages keep hiding the answers from a
  voter until they are finished (said they're done or saw every case),
  because they might still do more.
- **Results** (`AdjudicationResults`). Every figure shows the votes behind
  it ("sided with the AI in 9 (31 votes)"), each case shows its vote count,
  and a case with fewer than 3 votes in a group (`MIN_VOTES`) is "too few
  votes to call" and isn't counted as siding with anyone. Applied within
  each group separately. Groups stay apart as in ADR 0019.
- **Existing votes.** Nothing is changed or moved. Someone who pressed
  Submit all before batches (no assignments) keeps every vote counting.
  Someone who started but never submitted gets batches like anyone else;
  their saved answers appear pre-filled when those cases come up, and count
  once that batch is submitted.

## Consequences

- About 5 minutes per batch; more people can take part, and partial effort
  counts.
- Disputed cases collect votes about twice as fast as controls (7 vs 3 per
  batch over 16 each). Balance is within each kind, not across kinds; the
  disputed cases are what the check is for.
- A batch someone opens and abandons still counts towards the balance, so
  its cases may end up a vote or so short. Two people starting at the same
  moment may get overlapping picks. Both only make the spread a little less
  even.
- With few voters many cases will show "too few votes to call"; the page
  says so rather than reporting a one-vote majority.
