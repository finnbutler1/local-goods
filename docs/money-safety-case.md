# Money safety case

Nine ways money could go wrong in Local Goods, what stops each one, the test that shows it, and what is still open. Everything here runs in Stripe **test mode** against fictional people; no real money moves. "Proves" means "a test in this repository fails if the protection is removed". It does not mean the app is free of bugs. Tests use a fake Stripe, a fake AI client and (for most runs) an in-memory SQLite database; CI also runs the whole suite on MySQL 8.4 ([`ci.yml`](../.github/workflows/ci.yml)).

Run any test with `php -d memory_limit=2G vendor/bin/phpunit --filter <TestName>`.

## 1. An employee spends more than they have (including sales tax)

- **What stops it:** `RedemptionService::redeem` refuses an amount over the employee's balance ([`RedemptionService.php`](../app/Services/RedemptionService.php)). The amount it checks is the total with tax, and pay codes fix and re-check that total at issue and again at confirm ([`PayCodes.php`](../app/Services/PayCodes.php)). A second check after the AI answers catches a balance spent while the AI was thinking.
- **Test that proves it:** [`EmployeeBalanceSafetyTest`](../tests/Feature/EmployeeBalanceSafetyTest.php) (`test_a_pay_code_whose_total_with_tax_is_over_the_balance_is_refused_at_issue_and_at_confirm`); [`MoneyGuardsTest`](../tests/Feature/MoneyGuardsTest.php) (`test_a_balance_spent_while_the_ai_is_thinking_is_caught_by_the_second_check`, `test_spending_exactly_the_whole_balance_is_allowed`).
- **Still open:** the check is in application code. The database would accept an entry that overdraws (see the end of this page).

## 2. Two purchases at once use the same credits

- **What stops it:** the redemption runs in a transaction that locks the employee row before it reads the balance ([`RedemptionService.php`](../app/Services/RedemptionService.php)). When Stripe funds the payout, the lock order is employer, employee, purchases, then everything else ([`StripeFunding.php`](../app/Services/Payments/StripeFunding.php), ADR 0028), so two money operations can't each see the same unclaimed purchase.
- **Test that proves it:** [`MysqlRedemptionLockTest`](../tests/Feature/MysqlRedemptionLockTest.php) (a redemption waits for a lock held on the same employee); [`MysqlStripeFundingSnapshotTest`](../tests/Feature/MysqlStripeFundingSnapshotTest.php) (a redemption doesn't claim a purchase another transaction just claimed, and the lock order); [`EmployeeBalanceSafetyTest`](../tests/Feature/EmployeeBalanceSafetyTest.php) (`test_two_pay_codes_live_at_once_can_not_spend_the_same_credits`). The MySQL tests skip when the database isn't MySQL, so they run in the CI MySQL job.
- **Still open:** no test has two real connections reach the balance check at the same moment. The two-codes test is a simulation, and the MySQL tests hold one lock and check that the other side waits.

## 3. Held credits get spent

- **What stops it:** a redemption the fraud check holds is taken out of the employee's balance while a person decides, and a held redemption earns the merchant nothing ([`CreditLedger.php`](../app/Services/CreditLedger.php), `creditMerchant`).
- **Test that proves it:** [`EmployeeBalanceSafetyTest`](../tests/Feature/EmployeeBalanceSafetyTest.php) (`test_a_payment_held_for_review_keeps_its_credits_set_aside`); [`MoneyGuardsTest`](../tests/Feature/MoneyGuardsTest.php) (`test_a_held_redemption_cannot_earn_the_merchant_anything`); [`EmployerRefundTest`](../tests/Feature/EmployerRefundTest.php) (`test_held_credits_cannot_be_given_to_employees`).
- **Still open:** nothing found beyond the balance guard gap below.

## 4. A merchant is paid before the fraud check clears

- **What stops it:** `TransferGate::allows` re-reads the row and says yes only if it is approved, the merchant earning is in the ledger, and either the AI cleared it or a person approved it ([`TransferGate.php`](../app/Services/Payments/TransferGate.php)). `MerchantPayouts::sendOne` is the only code that creates a Stripe transfer and asks the gate first ([`MerchantPayouts.php`](../app/Services/Payments/MerchantPayouts.php)). The fraud check fails closed: an AI error or unusable reply is a hold.
- **Test that proves it:** [`FraudGateTest`](../tests/Feature/FraudGateTest.php) (`test_only_the_payout_sender_creates_transfers_and_it_asks_the_gate_first` scans the source; `test_with_the_gate_closed_no_flow_creates_a_transfer`; `test_an_ai_failure_or_invalid_reply_is_a_hold_and_never_transfers`; `test_approve_sends_exactly_one_transfer_even_on_double_clicks_and_retries`); [`MoneyGuardsTest`](../tests/Feature/MoneyGuardsTest.php) (the gate ignores a stale copy and a person's deny closes it).
- **Still open:** the fraud check is measured, not perfect. On the locked holdout it caught 17 of 26 suspicious redemptions ([/eval](https://uselocalgoods.com/eval)). Money the AI clears and a person would have held is not stopped by this gate.

## 5. A refund is bigger than the sale

- **What stops it:** `CorrectionLimits` counts every earlier refund (amounts, item quantities, the tax snapshot) and `CorrectionDecisions` re-checks them at approval ([`CorrectionLimits.php`](../app/Services/Corrections/CorrectionLimits.php), [`CorrectionDecisions.php`](../app/Services/Corrections/CorrectionDecisions.php)). Only a platform admin decides, with a reason.
- **Test that proves it:** [`RedemptionRefundTest`](../tests/Feature/RedemptionRefundTest.php) (`test_never_more_than_was_paid_across_every_refund`, `test_items_can_only_be_refunded_as_many_times_as_they_were_bought`, `test_two_admins_approving_the_same_request_refund_it_once`); [`EmployeeBalanceSafetyTest`](../tests/Feature/EmployeeBalanceSafetyTest.php) (`test_the_ledger_refund_writes_what_it_is_told_and_the_cap_is_enforced_by_correction_limits`).
- **Still open:** `CreditLedger::refundRedemption` only requires a positive amount. The cap lives one layer up, so new code that skipped `CorrectionLimits` could over-refund. The test above documents this on purpose.

## 6. A forged Stripe webhook adds credits

- **What stops it:** the webhook route is outside the session and CSRF group, and the signature is its only authentication ([`StripeWebhookController.php`](../app/Http/Controllers/Stripe/StripeWebhookController.php), `Webhook::constructEvent`). Live-mode events are refused. Events are de-duplicated, and a payment whose amount or currency doesn't match the purchase is refused ([`CreditLedger.php`](../app/Services/CreditLedger.php), `markPurchasePaidByStripe`).
- **Test that proves it:** [`StripeWebhookTest`](../tests/Feature/StripeWebhookTest.php) (`test_the_signature_is_required`, `test_live_mode_events_are_refused`, `test_a_replayed_event_changes_nothing`, `test_an_amount_that_does_not_match_is_refused`, `test_in_simulated_mode_the_webhook_refuses_everything`); [`MoneyGuardsTest`](../tests/Feature/MoneyGuardsTest.php) (`test_a_stripe_payment_is_recorded_once`).
- **Still open:** the webhook secret's strength and storage are outside the repository (a Beanstalk setting). Nothing here tests Stripe's own signing.

## 7. The ledger drifts from Stripe's real balances

- **What stops it:** the solvency rule refuses a money step that would leave Local Goods owing more than it holds ([`SolvencyGuard.php`](../app/Services/Solvency/SolvencyGuard.php)). Reconciliation compares Stripe's payments, transfers, reversals and refunds with the ledger and lists any that differ ([`StripeReconciliation.php`](../app/Services/Payments/StripeReconciliation.php)). A check runs on every deploy.
- **Test that proves it:** [`SolvencyGuardTest`](../tests/Feature/SolvencyGuardTest.php) (`test_an_unbacked_promise_is_refused_and_nothing_is_written`, `test_paying_out_more_than_a_merchant_is_owed_is_refused`); [`SolvencyRuleTest`](../tests/Feature/SolvencyRuleTest.php); [`ReconciliationTest`](../tests/Feature/ReconciliationTest.php) (a payment Stripe has and the ledger doesn't, and a transfer missing from Stripe, are highlighted); [`FraudGateTest`](../tests/Feature/FraudGateTest.php) (`test_reconciliation_still_matches_after_clears_approvals_and_denials`).
- **Still open:** reconciliation reads Stripe when someone runs it; it is not a continuous watch. Money Stripe already moved is recorded and flagged, never refused. Tests use a fake Stripe, so they show the comparison logic, not Stripe's real behaviour.

## 8. A delayed payment method settles late or fails after credits are added

- **What stops it:** credits are added only when Stripe sends a signed `payment_intent.succeeded` ([`StripeWebhookHandler.php`](../app/Services/Payments/StripeWebhookHandler.php)). Employers can pay by card, Apple Pay or Google Pay; bank debits and other delayed methods are off in the Stripe Dashboard. That is a Dashboard setting, not code: [`StripeCheckout.php`](../app/Services/Payments/StripeCheckout.php) deliberately names no payment methods, so Checkout follows the Dashboard.
- **Test that proves it:** [`StripeWebhookTest`](../tests/Feature/StripeWebhookTest.php) (`test_a_failed_payment_keeps_the_purchase_pending_with_the_reason`, `test_a_verified_payment_marks_the_purchase_paid_and_writes_one_entry`); [`SecurityPageTest`](../tests/Feature/SecurityPageTest.php) checks that the public page says this.
- **Still open:** no test can see the Dashboard setting. If someone enabled a bank debit there, only the `payment_intent.succeeded` rule would stand in the way, and disputes and returned payments would still be unhandled. See the end of this page.

## 9. An employer is refunded credits that were spent or promised

- **What stops it:** an employer refund covers unspent credits only. It is held at approval, taken from the newest payment first, never takes what a transfer has claimed, and never refunds either platform fee, the percent fee or the employer platform fee per participating employee (ADR 0042, switched off during the pilot) ([`CorrectionLimits.php`](../app/Services/Corrections/CorrectionLimits.php) `employerPlan`, [`CorrectionDecisions.php`](../app/Services/Corrections/CorrectionDecisions.php)).
- **Test that proves it:** [`EmployerRefundTest`](../tests/Feature/EmployerRefundTest.php) (`test_only_unspent_credits_can_be_refunded`, `test_the_refund_splits_newest_first_and_never_takes_what_transfers_claimed`, `test_the_platform_fee_is_never_refunded`, `test_refunded_money_never_funds_a_later_stripe_transfer`; [`EmployerPlatformFeeChargeTest`](../tests/Feature/EmployerPlatformFeeChargeTest.php) `test_the_fee_is_never_refunded_and_never_funds_a_merchant_transfer`, `test_a_refund_stripe_reports_failed_fails_the_correction_and_keeps_the_hold`); [`MysqlEmployerRefundSnapshotTest`](../tests/Feature/MysqlEmployerRefundSnapshotTest.php) for the MySQL lock behaviour.
- **Still open:** the same race-test gap as in section 2. A refund Stripe reports failed keeps the hold until a person closes it.

## What this doesn't cover yet

- **Disputes and returned payments.** Nothing handles `charge.dispute.*` events or a payment that is returned after it succeeded. Test mode only, and a reason bank debits stay off.
- **A real two-connection race test on MySQL.** Pay-code issue and confirm, and `redeem`, have not been tested with two connections arriving at the balance check at the same moment.
- **A database-level negative-balance guard.** The check is in application code, inside the locked transaction. The database would accept an overdrawing entry.
- **The uncapped low-level refund.** `CreditLedger::refundRedemption` trusts its caller to have applied `CorrectionLimits`.

These are also listed in [CLAUDE.md](../CLAUDE.md) and on the [Security & Trust](https://uselocalgoods.com/security) page.
