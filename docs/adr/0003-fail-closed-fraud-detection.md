# 0003. Fail-closed fraud detection

**Status:** Accepted, implemented

## Context

Redemption Fraud Detection asks Claude to score each redemption. Network calls
fail, keys expire, models occasionally refuse, and a reply can be malformed. We
had to decide what happens to a redemption when the AI cannot give a valid
answer. If the app approved anyway, an outage would become an open door for
credit-to-cash collusion.

## Decision

**Fail closed.** When the AI call fails, Local Goods holds the redemption
instead of approving it, the way a bank's fraud team would design it.

- Any of these produces a hold with risk score 100 and `scored_by = fallback`:
  - an exception
  - a missing API key
  - a refusal
  - invalid JSON
  - a score out of range
  - an unknown decision
  - an empty explanation
- If Claude says `clear` but scores **70 or higher**, the app holds anyway
  (`config/fraud.php`, `hold_if_score_at_least`).
- Only anonymous signals are sent to the AI, never names.

Code: [`RedemptionFraudDetector`](../../app/Services/Fraud/RedemptionFraudDetector.php).
Tests: [`RedemptionFraudDetectorTest`](../../tests/Feature/RedemptionFraudDetectorTest.php).

## Consequences

- An AI outage can never mark a redemption as approved. Once Stripe payouts
  are built (planned), that means an outage can never release a fraudulent
  payout. Today no money moves.
- During an outage, honest redemptions are held too, which annoys employees.
  Their credits stay reserved, so nothing is lost while they wait.
- Held redemptions need a human. Today they appear on the BID admin view with
  the AI's explanation. Approve and reject buttons are **planned**.
- The AI call happens outside the database transaction, so a slow reply never
  locks SQLite.
