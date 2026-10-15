# 0013. Merchant Eligibility Matching: an AI suggestion, a person's decision, measured against locked labels

**Status:** Accepted, implemented.

## Context

Local Goods credits may only be spent at independent merchants in the BID.
Whether a business is independent comes from the independence rule in
`CLAUDE.md`: DC-metro ownership with no outside parent, private-equity or
public-company control; five or fewer locations including sister brands; not
a franchisee; "undetermined" if public sources can't settle it.

We already have 224 real candidates from DC Open Data
(`data/merchant_candidates.csv`), and Finn hand-labeled 60 of them (40 dev,
20 holdout) with a public source each, locked in
`data/merchant_labels_lock.json` before any AI run. The second named AI
function has to be built and measured with the same discipline as
Redemption Fraud Detection ([ADR 0003](0003-fail-closed-fraud-detection.md)).

## Decision

- **What the AI sees.** Only public license facts: name, address, our
  category and the license's business activity (fetched by
  `eligibility:fetch-activity`, never owner names). The input is built from a
  fixed allowlist (`MerchantEligibilityMatcher::INPUT_FIELDS`), and the
  labels live in another file that the check never reads. Tests check that no
  label, note or evidence link reaches the AI, and that the prompt holds the
  rule word for word and names no labeled business.
- **No web access, said out loud.** Claude judges from its own training
  knowledge. The prompt tells it so and asks it to answer `undetermined`
  rather than guess when it doesn't recognize a business. Every page that
  shows its reasoning adds a fixed notice that it didn't search the web and
  may not know about recent sales, closures or new owners.
- **Structured answer, checked.** `eligible`, `classification` (independent,
  local_chain, franchise, national_chain, outside_control, undetermined),
  `confidence` 0–1, `reasoning`, `needs_human_review`. An answer is invalid if
  a field is missing, the confidence is out of range, the reasoning is blank,
  or `eligible` disagrees with the classification.
- **Fails closed.** An API error, a refusal or an invalid answer becomes
  `undetermined`, not eligible, confidence 0, flagged for a person. It is
  never a guess. `undetermined` or confidence below 0.7 is always flagged,
  whatever the AI said (the counterpart of the fraud check's score-70 rule).
- **A person decides.** The BID admin's queue (`/bid-admin/merchants`) shows
  each assessed candidate with the AI's answer and Approve, Reject and Needs
  research buttons. Each decision is a new row in the append-only
  `merchant_review_decisions` log: who, when, an optional note, and which AI
  answer (classification and prompt version) they saw. Approving only records
  the decision. It never creates a demo merchant or puts a real business into
  any transaction. Hand labels are not shown in the queue, so they can't steer
  the decision. Judges see the queue read-only (`BlockJudgeWrites` plus the
  `decide-merchant-eligibility` gate).
- **Production never calls the API for this.** `merchant:eval` writes the AI's
  answers (no labels) to `data/merchant_assessments.json`, which ships with the
  app. The queue shows exactly the answers that were measured.
- **Measured like the fraud check.** `php artisan merchant:eval` refuses to
  run unless the labels match the lock. It scores one split per run, and dev
  and holdout are never pooled. The prompt may change using dev cases only.
  The holdout is scored once per prompt version: a second holdout run with
  the same prompt is refused, and the results list every prompt version that
  has seen it. Replies are cached by model, prompt and input, so a re-run
  costs nothing, and `--dry-run` reports the number of API calls before any
  are made.
- **Honest numbers for imbalanced labels.** Only 14 of 60 labels are
  independent, so answering "not eligible" every time already scores well.
  Each split reports the eligible yes/no agreement next to that baseline, plus
  how many of Finn's independent businesses the AI found and how many of the
  AI's "eligible" calls were right (X of Y, Wilson 95% intervals). It also
  reports a confusion matrix, matches by Finn's fail reason, and undetermined
  separately. No label is undetermined yet, so "did the AI also say
  undetermined?" is reported as not measurable. Every disagreement is listed
  with Finn's note and the AI's reasoning.
- **No real names in public.** The candidates are real businesses that never
  joined. Logged-out visitors and the shared public judge account (its link is
  in the README) see totals only, on both `/eval/merchants` and the queue.
  Named reviewers and admins see names, with "Candidate — not a Local Goods
  participant." on every one (`User::maySeeCandidateNames`). The committed
  files under `docs/evals` and `data/` do name the businesses, because the
  repo holds the source data.

- **The AI only recommends (added after the dev runs).** It is a triage
  tool: it screens out obvious chains and franchises, routes anything it
  can't verify to a person, and never approves a merchant on its own. An AI
  "eligible" shows as "AI recommends eligible — confirm before approving".
  `MerchantReviewDecision` refuses any decision not made by a BID or
  platform admin, and a test checks that only the review queue writes one.
- **Frozen prompt.** After one dev-only change, the prompt was frozen as
  `458e8c2d` (`eligibility.frozen_prompt_version`, checked in CI). The holdout
  runs only with that version, once. Holdout: 0 of 17 chains and franchises
  called eligible, 0 of 3 independents found, and agreement equal to the
  always-"not eligible" baseline (17 of 20). On dev it found 2 of 11
  independents.

## Consequences

- Without web access, the AI says `undetermined` for most small local
  businesses (2 of 11 independents recognized on dev, 0 of 3 on the holdout).
  That is the honest answer, and it shows up as a low "independent found" rate
  rather than as made-up confidence. **Future work:** a vetted web search
  tool. That needs a new prompt version and a fresh unseen holdout, since this
  holdout has now been seen.
- Real candidates stay out of the demo. Approving one doesn't make it
  redeemable. Turning an approved candidate into a participant is a separate,
  later step (consent, Stripe Connect onboarding).
- Each prompt change costs one dev run (40 calls). Running the holdout spends
  it for that prompt version.
