# 0019. Personal contacts vote in the blind adjudication, reported separately

**Status:** Accepted, implemented.

## Context

Blind adjudication
([ADR 0011](0011-blind-adjudication-of-eval-disagreements.md)) lets BID staff
and contest judges check the fraud eval cases where Finn's label and the AI
disagree. Only a few of them will vote, so the sample is small. Friends,
family and classmates are willing to vote too, but they know Finn, so they
may lean towards Finn's labels (or try hard not to). Mixing their votes into
the result would make it look larger and more independent than it is.

## Decision

- **A new reviewer type, "Personal contact (knows Finn)"**
  (`ReviewerType::PersonalContact`). Created on `/admin/reviewers` like any
  named reviewer, each with their own magic link
  ([ADR 0010](0010-magic-links-for-reviewers.md)). The existing "Friend" type
  is unchanged: full read-only reviewer access, no vote. The public judge
  link still can't vote.
- **Who may vote is one list.** `ReviewerType::mayAdjudicate()` (BID,
  contest judge, personal contact) drives `User::canAdjudicate()` and the
  `adjudicate` gate; `countsInAdjudicationHeadline()` (BID, contest judge)
  says whose votes make the result.
- **Same blind voting.** The same page, the same cases in their own random
  order, facts only (never Finn's label or note or the AI's call), one vote
  per person per case (the existing unique index), the same opening question
  about the eval page. No new write routes, so
  `BlockJudgeWrites::ALLOWED_ROUTES` is unchanged.
- **Voting only.** `KeepPersonalContactsToAdjudication` (in the web group
  after `BlockJudgeWrites`) lets a personal contact open only the voting
  page, their account page (their own feedback), feedback, log out and the
  magic-link page. The judge home and the landing page redirect to
  `/adjudicate`; everything else is a 403. So: no judge tour, no "view as",
  no eval, admin, BID, employer or merchant pages. The menu has one item and
  no "View as" button. `PersonalContactAccessTest` walks every page;
  `JudgeReadOnlyTest` tries every write route as a personal contact too.
- **Reported apart, never merged.** `AdjudicationResults::for()` keeps
  `all` and `unseen` for BID and contest judges only, numbered as before, and
  adds `personal` with the same two views for personal contacts, numbered on
  their own ("Contact 1"). `/eval` and the admin page show them in a separate
  section headed "Personal contacts (know Finn): not counted in the result
  above". The judge home count and the admin participant tables are split the
  same way. No total anywhere adds the two groups.
- **Names.** Personal contacts' names and emails never appear on public or
  reviewer pages. Admin pages show initials only (`User::adminLabel()`,
  `adminEmail()`): reviewers list, adjudication, feedback inbox, accounts
  table. `EmployeeNamePrivacyTest` sweeps every page as every role.
- **Nothing existing changes.** No migration (`users.reviewer_type` is a
  string). The published case set, existing votes, labels, the eval and the
  headline are untouched.

## Consequences

- More people can check the disputed cases without inflating the
  independent result. A reader can compare the two groups side by side.
- Personal contacts can't see the eval page while signed in, which keeps
  them blind; they could still open it signed out in another window, which
  is why the opening question applies to them too.
- Group membership is read from the reviewer's type when results are shown,
  so changing a voter's type would move their votes. There is no screen to
  change a type, so this can't happen from the app.
