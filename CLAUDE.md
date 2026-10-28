# Local Goods — project brief for Claude

Read this first in every session. It is the source of truth for what we are
building, for whom, and the rules for working in this repo.

## Current milestone

**Districts, partnerships and merchant joining — done (2026-10-28, ADR
0031).** Three additive migrations (`2026_10_30_000001…000003`, snapshot
`before-districts`). Fraud prompt (`8d5e1e70`), eligibility prompt
(`458e8c2d`), signals, AI inputs, eval data and locks unchanged.

- `districts`: the Golden Triangle row, copied from
  `data/sources/golden_triangle_bid.geojson` (hash, source, dates;
  `config/districts.php`). Never fetched at runtime. Merchants have
  `district_id`, `latitude`/`longitude`, `district_approved_at`, `live_at`;
  employers have `district_id` (program district). Demo points:
  `DatabaseSeeder::DEMO_COORDINATES` (all inside; two demo addresses moved).
- `App\Services\Districts\DistrictRules` is the one place for the rules:
  approve only inside (`approveMerchant` + `Merchant` saving hook);
  `ensureRedeemable` is the one line at the top of `RedemptionService::redeem`
  (refuses before scoring; employers with no district are unrestricted);
  `PayCodes` checks before issue and before the claim; wallet lists
  `Merchant::redeemableFor`. Never pass district fields to signals or the
  AI (`FraudInputDistrictTest`). Test merchants for the seeded employee use
  `Tests\Concerns\PlacesMerchantsInTheDistrict`.
- Partnerships: `PartnershipSettings` only (BID or platform admin, reason,
  reviewed change id, status forward only), append-only
  `partnership_changes`; unique `exclusive_active_key` (set by the model)
  = one active exclusive per district. `PartnershipRevenueShare` reads
  platform ledger lines, display only. `/bid-admin/partnership`: judges see
  words, no figures.
- Addresses: `DcMarGeocoder` (MAR address points layer on DC GIS, keyless;
  citizenatlas is retired, MAR 2 needs a key) → `AddressLookup`
  (`address_lookups`; checker rows keep no typed text) → `DistrictCheck`
  (same `isInside` as approval). Tests: `Tests\Concerns\FakesDcAddresses`
  (prevents stray HTTP).
- Joining: `/apply` (prototype notice first, "I understand this is a
  prototype", privacy note, honeypot `website`, 3 per 10 min per IP; no
  outside calls). `MerchantApplications` is the only status changer
  (applied → eligibility_checked → bid_approved | declined →
  stripe_onboarding → live; conditional UPDATE + append-only event). Status
  link `/apply/status#<id>.<token>` (hash only; JS `form[data-status-link]`).
  The AI only suggests (name, address, category, empty activity; never the
  answer or email). Queue `/bid-admin/applications` (`decide-merchant-applications`;
  judges: masked email, disabled). Go live: one line in
  `StripeOnboarding::apply` → `goLiveIfReady`. `applications:purge
  --before=YYYY-MM-DD` (run by hand 2027-01-01; also checker lookups > 30
  days). Test helper `Tests\Concerns\MakesApplications`.
- Demo: one demo partnership and two `is_sample` applications, all
  labelled; partnership and application tables are in `DEMO_TABLES` and
  `DemoResetter::DISTRICT_TABLES` (seed-if-empty adds just them).
- Pages: `/district` (SVG from the stored polygon, `<x-district-map>`,
  group with linked pins, text alternative, checker), `/who-does-what`
  (8 steps, `[data-tabs]`), `/bid-admin/impact.csv` (aggregates only).
- Tests: `DistrictBoundaryTest`, `DistrictRulesTest`, `FraudInputDistrictTest`,
  `DistrictCheckTest`, `DistrictMapPageTest`, `PartnershipTest`,
  `PartnershipPageTest`, `PartnershipRevenueShareTest`,
  `MerchantApplicationPipelineTest`, `ApplicationEligibilitySuggestionTest`,
  `ApplyFormTest`, `ApplicationStatusLinkTest`, `ApplicationQueueTest`,
  `PurgeApplicationsTest`, `MerchantJoinOnboardingTest`, `DistrictsDemoTest`,
  `WhoDoesWhatPageTest`, `ImpactExportTest`, `DistrictsMigrationSafetyTest`.

**Business model and projections — done (2026-10-28, ADR 0030).** No
migration; money logic, ledger, Stripe, fee settings, fraud prompt, AI
input, eval data and locks unchanged.

- One source: `config/projections.php` (each assumption: low/base/high,
  unit, `source_url` or exactly "Assumption: to validate in customer
  discovery", note, `verified_on`). Only `demand`/`revenue` groups vary
  by case. Scenarios a/b/c (`bids_by_year_end`); card issuing off.
  Go-to-market keys under review: `account_manager_bids_per_manager`,
  `sales_cost_per_new_employer`, `bid_launch_cost`.
- `App\Support\Projections\BusinessModel` (pure, 72 months, P&L 48;
  never reads MerchantFee/PlatformFee/ledger/Stripe, nothing in
  `app/Services` calls it), `Projection`, `WhatItTakes` (scenario A, one
  BID at full ramp, searched), `Figures`, `ProjectionDocs`. Customer funds
  and breakage are never revenue.
- Pages: `/projection` public, district numbers only; `/business-model` and
  `/business-model.csv` for judge, BID admin, platform admin (personal
  contacts refused by their middleware). "Projection, not results" on
  every screen. Fee/revenue/profit figures only there and in the README
  `## Business model` section (`PublicClaimsTest`).
- **After changing an assumption run `php artisan projections:docs`**
  (rewrites the marked blocks in README and `docs/finance/MODEL.md`;
  `BusinessModelDocsTest` fails until you do).
- Tests: `BusinessModelTest`, `ProjectionsConfigTest`,
  `ProjectionFiguresTest`, `ProjectionPageTest`, `BusinessModelPageTest`,
  `BusinessModelDocsTest`.
- Tests that spend seeded balances seed on a fixed date
  (`Tests\Concerns\SeedsOnAFixedDate`, 2026-10-24): the seed's 8-week
  window gives one allowance fewer once its start crosses a month.

**Merchant fee, ready but at 0 — done (2026-10-24, ADR 0029).** One
migration (additive: `merchant_fee_changes`, redemption fee snapshot
columns, `merchant_payouts.fee_cents`, `corrections.fee_cents`, all 0).
Fraud prompt (`8d5e1e70`), signals, AI input, eval data, locks,
adjudication, `TransferGate` and the employer `PlatformFee` unchanged.

- Setting: `App\Support\MerchantFee` (bps ≤ 1000, fixed ≤ 100¢; fee =
  min(total, half-up(total × bps) + fixed), on the total incl. tax). The
  newest `merchant_fee_changes` row is the fee, no row = none (append-only
  audit log, not a demo table). Only `MerchantFeeSettings::change`,
  platform admin (`change-merchant-fee`), reason, reviewed-against id.
  `/bid-admin/fees`: Review (writes nothing) → Confirm (checkbox).
  **Never set a nonzero fee on live or in a seeder** (`MerchantFeeSeedTest`);
  tests use `Tests\Concerns\SetsMerchantFee`.
- Snapshot on the redemption in `RedemptionService::redeem` (`?MerchantFee
  $fee`; pay code confirm passes the one shown, `fee_change`, else
  `PayCodeController::FEE_CHANGED`). **Never pass the fee to signals or the
  AI** (`FraudInputMerchantFeeTest`).
- Ledger: `creditMerchant` adds `merchant_fee` (−) / `platform_merchant_fee`
  (+, platform) when the fee > 0. Stripe row = total − fee (`fee_cents`),
  no row when that is 0; `unclaimedCents` counts amount + fee. Refunds:
  `CorrectionLimits::feeShare` (cumulative, stored on the correction),
  `merchant_fee_returned` / `platform_merchant_fee_returned`, reversal =
  refund − share, `fee_only` action when that is 0. Invariant unchanged.
- Reconciliation: transfer + fee = redemption total; platform fee lines =
  cleared fees − completed refund shares; "includes $X fees kept".
- Screens: merchant fee sentence, Sale / Local Goods fee / You receive
  (`partials/merchant-fee`) on cleared rows, Stripe payouts, pay code
  review; CSV `Local Goods fee`, `You receive` after `Total`. **Judges
  count as the public: no fee figures for them** (`MerchantFee::showsFiguresTo`,
  `PublicClaimsTest` sweeps judge pages). README/TRANSPARENCY words only.
- Merchant "Cleared sales this month" net of completed refunds (Sales,
  Refunds, Net), `CompletedRefunds` timing.
- Tests: `MerchantFeeTest`, `MerchantFeeSettingsTest`, `MerchantFeeSaleTest`,
  `MerchantFeeRefundTest`, `MerchantFeeReconciliationTest`,
  `MerchantFeeDisplayTest`, `FraudInputMerchantFeeTest`, `MerchantFeeSeedTest`,
  `MerchantFeeMigrationSafetyTest`, `MerchantClearedNetOfRefundsTest`.

**Refunds and corrections — done (2026-10-24, ADR 0028).** One
migration (additive: `corrections`, `correction_items`,
`correction_actions`, nullable `credit_ledger_entries.correction_id`).
Fraud prompt (`8d5e1e70`), signals, eval data, locks, adjudication,
`TransferGate`, `RedemptionService` and `MerchantPayouts` unchanged.

- Requests: `CorrectionRequests` (employee/merchant on a cleared
  redemption, merchant on a Stripe payout, HR on unspent credits); own
  items only (404), `throttle:5,1,<name>`, one open per item via the unique
  `corrections.open_key`. Shared `partials/correction-request` (judges get
  disabled fields, no form). Asking moves no money.
- Decisions: `CorrectionDecisions`, **platform admin only**
  (`decide-corrections`), always a reason the requester sees. Queue
  `/bid-admin/corrections` (BID admin and judges read-only). Statuses
  requested → approved → processing → completed/failed, rejected, closed
  (Close releases employer holds; refused while a step is unknown).
- Approval: claim (requested only) + lock the item + re-check every
  counted correction (`CorrectionLimits`: amounts, item quantities,
  cumulative snapshot tax, each payment's `refundableCents`). Stripe after
  commit in `CorrectionProcessor` (key per action/attempt, never throws).
- Redemption refund: sent transfer → `reverseTransfer`, ledger on
  `transfer.reversed` (`refund`, `merchant_refunded`, `refund_reversal`);
  simulated → at once, netted from the merchant; not-yet-sent or
  dashboard-reversed payout → refused (`NOT_SENT_YET`,
  `REVERSED_IN_DASHBOARD`). Employer refund: hold at approval, newest
  payment first, credits only, `createRefund`, `refund.created/updated/failed`.
  Merchant adjustment reuses `MerchantPayouts::send` on the owed row.
  `CreditPurchase::unclaimedCents` subtracts refunds.
- Webhook: `transfer.reversed` records our reversals first; the old
  "owed again" path only for unexplained reversed money. Reconciliation
  matches reversals and refunds; Tax collected is net of refunds
  (`net_tax_cents`, by `completed_at`); CSV has refund rows.
- Never send corrections to the AI (`FraudInputCorrectionsTest`).
  Seed: one completed partial refund (Cold brew + tax) and one rejected
  request at Farragut Grind, never Jordan; tables in `DEMO_TABLES` and
  `EMPTY_AFTER_SEED`. Tour step 9. Stripe trial fixtures in
  `tests/Fixtures/stripe/corrections/` (scrubbed; `StripeTrialFixturesTest`).
  Tests: `CorrectionRequestTest`, `RedemptionRefundTest`,
  `EmployerRefundTest`, `MerchantAdjustmentTest`, `CorrectionRetryTest`,
  `CorrectionWebhookTest`, `CorrectionReconciliationTest`,
  `CorrectionTaxTest`, `CorrectionQueueTest`, `CorrectionPrivacyTest`,
  `CorrectionsDemoTest`, `CorrectionsMigrationSafetyTest`,
  `MysqlCorrectionLockTest`. Test helper `Tests\Concerns\MakesCorrections`.
- Spending figures (BID impact, participation, employer, landing totals,
  recirculation input) are net of completed refunds via
  `CompletedRefunds` (ledger `merchant_refunded`, dated when completed),
  labelled "net of refunds"; visits unchanged (`SpendingNetOfRefundsTest`).

**DC sales tax at redemption — done (2026-10-24, ADR 0027).** One
migration (additive: `merchant_products.tax_category` backfilled from the
merchant; item category/rate; `tax_basis`, `subtotal_cents`, `tax_cents`,
`tax_lines` on redemptions and pay codes). Fraud prompt (`8d5e1e70`),
signals, eval data, ledger rules, webhook, `TransferGate` and
reconciliation unchanged.

- `amount_cents` stays the total (subtotal + tax): the AI, ledger debit,
  merchant earning and Stripe transfer all use it as before. **Never add
  tax fields to the AI input** (`FraudInputTaxTest`).
- Rates only in `config/tax.php` (basis points, `effective_from` in DC
  time, sources, `verified_on`); a change is a new row. Prepared food 10%,
  general 6% then 7% from 2027-10-01 (OTR's own notice conflicts: body says
  2026, banner/FAQ say 2027; we follow the banner), exempt 0%.
  `App\Support\SalesTax` (per category on its subtotal, half up) and
  `TaxBreakdown` (snapshot; `added`, `included`, null = before tax display).
- Tax is added on menu items only; a typed-in amount or Other amount is
  the shop's total, "Amount includes any tax charged by the shop."
  `RedemptionBasket::build` returns `tax`; `redeem(..., ?TaxBreakdown $tax)`
  (default included) checks items + tax = amount and writes the columns.
  Pay codes fix tax at issue and confirm with it.
- Screens read only the snapshot (`partials/tax-breakdown`). Merchant:
  Tax collected (`MerchantTaxSummary`, cleared `added` orders, this and
  last month), `merchant.redemptions.csv` (initials, formula guard), menu
  tax category with "Confirm this with your accountant." Employer: one
  note, no numbers (`EmployerTaxNoteTest`). Never "tax-free", never tax
  advice: merchants file and remit.
- Demo: `DEMO_MENUS` optional 4th value overrides the default category;
  seeded typed-in rows are `included`; `DemoHistorySeeder::TAXED_ORDERS`
  adds three taxed Farragut Grind orders (never Jordan). Tour step "See tax
  at checkout".
- Tests: `SalesTaxTest`, `SalesTaxRedemptionTest`, `SalesTaxDisplayTest`,
  `MerchantTaxTest`, `EmployerTaxNoteTest`, `FraudInputTaxTest`,
  `SalesTaxDemoTest`, `SalesTaxMigrationSafetyTest`.

**Phone-first pass — done (2026-10-24).** Front end only: no route,
controller logic, money, Stripe, prompt, eval, lock or migration change.
Checks that ran: `docs/accessibility.md` "Phone-first pass".

- Tables: one pattern. `<x-rtable label="…">` around
  `<table data-rtable="stack">`; below 640px each row is a card. Every
  body cell needs `data-label="Column"`, `data-primary` (card title) or
  `data-label=""` (actions). Only a short table that fits 320px may be
  `data-rtable="fit"`. `ResponsiveTablesTest` sweeps every page.
- Long emails/links: `<x-breakable :value>` (`<wbr>` after @ . / _ -);
  ids: `wrap-anywhere-safe`. Never `break-all` (a test checks). Tests
  that look for an email must strip `<wbr>` (`EmployeeNamePrivacyTest`
  does).
- Phones: every `x-button` size and select is 44px; standalone links use
  `tap-target`; summaries and checkbox/radio rows are 44px (rules in
  `app.css`). Fields are at least 16px. Page titles use `text-title`
  (clamp). Hover tooltips also open on tap (`data-bar`).
- Safe areas: the app-shell footer (demo notice, now shown on phones
  too) carries `pb-[calc(10rem+env(safe-area-inset-bottom))]`; tab bar,
  toast and Feedback button use the insets. Honesty lines must never sit
  in anything hidden on phones (`PhoneHonestyTest`).
- Desktop (1280px) unchanged: before/after screenshots match apart from
  sub-pixel kerning around `<wbr>`.

**Pay in person with pay codes — done (2026-10-23, ADR 0026).** One
migration (`pay_codes`, `redemptions.channel` default `to_go`, additive).
Fraud prompt (`8d5e1e70`), signals, model, eval data, ledger rules,
webhook, reconciliation logic and `TransferGate` unchanged.

- Wallet tabs: Order to go (old flow, default; `?merchant=`, `?q=` land
  there) and Pay in person (`?tab=in-person`, `pay_merchant=`, own error
  bag `payCode`, ids prefixed `pay-`; shared `employee/partials/basket-fields`).
  Real tabs (`[data-tabs]` in `app.js`, arrow keys); links without JS.
- `App\Services\PayCodes` is the only place codes change: 6 chars from
  `PayCodes::ALPHABET`, 10 min, one live per employee, single use (atomic
  claim), HMAC `code_hash` + encrypted `code`; `NOT_VALID` for every miss,
  5 misses/min per merchant (`failureKey`). Never log a code, never put it
  in a URL or JSON; `pay_code` is in `dontFlash`. Expiry is lazy
  (`expireStale`).
- Confirm = `RedemptionService::redeem(..., $items, Redemption::CHANNEL_IN_PERSON)`.
  The channel is display only (`channelLabel()`); **never pass it to
  signals or the AI** (`FraudInputByChannelTest`).
- Merchant: "Take a payment" on `/merchant`; the session keeps only the
  looked-up id (`Merchant\PayCodeController::SESSION_KEY`). Initials only.
  Held wording neutral, no fraud words.
- Cancel this payment: held in-person only, by its own merchant login or
  a BID/platform admin, never the employee (`RedemptionDecision::mayCancel`;
  the employee only cancels an unused pay code), via
  `RedemptionReviewService::cancel` (same `decide()` path, `review_rejected`
  back to the employee, decision `cancel`). Approve/deny stay admin only.
  Not eval or adjudication data.
- Seed: one in-person $10.00 at Farragut Grind (`DemoHistorySeeder::IN_PERSON_*`,
  never Jordan). `pay_codes` in `DEMO_TABLES` and `EMPTY_AFTER_SEED`.
- Tests: `PayCodeTest`, `PayInPersonWalletTest`, `MerchantPayInPersonTest`,
  `HeldPaymentCancelTest`, `ChannelDisplayTest`, `FraudInputByChannelTest`,
  `PayCodesMigrationSafetyTest`; FraudGateTest covers in-person flows.

**Transit lines brand, button states and menus — done (2026-10-23, ADR
0025).** Front end only: no route, controller logic, money, Stripe, prompt,
eval or migration change. Source of truth: `docs/brand/` (Transit, Logo A).

- Tokens by role in `resources/css/app.css` (`page`, `surface`, `band`,
  `ink`, `muted`, `line`, `edge`, `primary*`, `accent*`, `ok`, `hold`,
  `danger`, `focus`, `disabled-*`, `night*`). Light in `@theme`, dark in
  the two dark blocks (keep them identical). Never reintroduce forest,
  cream, paper, amber, sage or the awning. New pairs go in
  `ColorContrast::PAIRS` (checked light and dark).
- Fonts Overpass + Atkinson Hyperlegible Next (`@fontsource-variable`);
  digits come from Overpass via a `unicode-range` face (plain zero).
- `<x-brand-head>` (icons, manifest, theme script) in all three layouts;
  `<x-theme-toggle>`; `<x-logo>` (`on-dark`, `mark-only`). Footer tagline
  "Reroute your lunch."; hero "You work here. Spend here.".
- Motif only in: landing route line, `<x-eyebrow>`, `.merchant-rail` on
  participating merchants (never on candidates).
- Buttons: `unavailable:` variant (disabled/aria-disabled, not busy);
  `form[data-busy]` adds a spinner + `aria-busy`, cancels a second submit,
  disables submit buttons one tick later. Spinner only when motion allowed.
- `<x-menu>` disclosure (Account, More, View as, public Menu); sidebar max
  5, tab bar max 4, rest in More by the priority list in `app-shell`
  (Feedback visible for platform admin, Merchants for BID admin).
  `Role::navigation()` unchanged. Tests: `BrandLayoutTest`,
  `ThemeToggleTest`, `ButtonFeedbackTest`, `DropdownMenuTest`.

**Mission-first messaging — done (2026-10-23, ADR 0024).** No migration,
no money, Stripe, prompt or eval change.

- Landing: "You work here. Spend here.", "The commute-desk-commute loop",
  "Why it matters" (district first). Judge home: "Why Local Goods exists"
  (the old "What Local Goods is" heading fails `PublicClaimsTest`). README
  opens with the same mission.
- The 3x line only via `partials/recirculation-evidence` (label, rates and
  ratio from `config/impact.php`, pilot line, source); README carries the
  same label and source in its section. Never "Local Goods creates/delivers
  3x", never "guaranteed", never a measured result. No fee, revenue or
  interchange figures on public pages or the README (tests check).

**Product menus at the demo merchants — done (2026-10-22, ADR 0023).** One
migration (`merchant_products`, `redemption_items`, additive). Money rules,
fraud prompt (`8d5e1e70`), signals, model and eval data unchanged.

- Menus: `DatabaseSeeder::DEMO_MENUS` (keyed like `DEMO_MERCHANTS`, 4–8
  each). `Merchant::mayHaveProducts()` (demo names, never a candidate) is
  enforced on save. Reset demo restores them (both tables in DEMO_TABLES;
  `redemption_items` in EMPTY_AFTER_SEED); `demo:seed-if-empty` adds only
  the menus to a demo seeded before them.
- Redeem: GET `?merchant=` shows the menu (no auto-submit), POST
  `items[product_id]=qty` (0–20) plus `amount` = Other amount.
  `RedemptionBasket::build` computes the total from the menu prices;
  `RedemptionService::redeem(..., $items)` scores the amount as before,
  then stores items (name and price copied). Items plus their tax (ADR
  0027) always add up to the amount; a bare amount has no items. **Never pass items into signals or
  the AI** (`RedemptionItemsTest` checks the input is identical).
- Shown via `Redemption::itemsSummary()` (load `items`): wallet, merchant
  dashboard, Fraud review ("not sent to Redemption Fraud Detection"),
  employer returned-credit rows.
- `/merchant/menu` (`Merchant\MenuController`): edit name, price,
  description, on/off, add (max 12); own products only, throttled, judges
  read-only.

**Every demo merchant can receive Stripe test transfers — done
(2026-10-22, ADR 0022).** One migration
(`merchants.stripe_set_up_by_platform`, additive, kept by Reset demo).

- `/admin/reconciliation` → "Connect demo merchants to Stripe (test mode)"
  (`admin.stripe.demo-merchants`, `DemoMerchantStripeSetup`): v2 accounts
  with **no Stripe dashboard** (Stripe refuses ToS on behalf for Express:
  `tos_acceptance_on_behalf_not_allowed`), Stripe test identity values plus
  `btok_us_verified`. Only `DatabaseSeeder::DEMO_MERCHANTS` with no login,
  never a name in `merchant_candidates.csv`. Idempotent, test mode only.
- One merchant per request (`DemoMerchantStripeSetup::step`, about 7s; the
  CloudFront origin limit is 30s). The id is saved right after create, then
  the bank account is added and the status read once, with no waiting and
  no transfers in the step. The page auto-continues (`form[data-auto-submit]`,
  `?connecting=<round>`, stops at 2× merchants); without JS, "Continue
  connecting". Throttle 30/min. A cut-off press is resumed with the same
  idempotency keys.
- Label "Demo account set up by Local Goods in test mode"
  (`Merchant::PLATFORM_SETUP_LABEL`). Never use this path for anyone with a
  login or any real business; they self-onboard on Express pages.
- Scratch trials against real Stripe use their own `APP_KEY`/`APP_ENV`
  (a different `lg_instance` from Finn's local app) and delete throwaway
  accounts afterwards.

**Buy credits in one step, platform fee ready but off — done (2026-10-21,
ADR 0021).** One migration (`platform_fee_cents` default 0, nullable
`credit_ledger_entries.platform`). Money rules unchanged.

- Flow: `employer.purchases.store` → `/employer?pay=<ref>#buy` (Stripe's
  embedded Checkout, `ui_mode: embedded_page`, Stripe.js `endive`,
  `createEmbeddedCheckoutPage`; `initEmbeddedCheckout` throws in that
  version) → `employer.purchases.checkout` (JSON client secret) → return
  `?checkout=returned` → "Confirming with Stripe…" polls
  `employer.purchases.status`. Simulated: one press, paid at once. GETs
  never create anything. `return_url` via `AppUrl::route` (APP_URL in
  production).
- Cancel/Try again: `StripeCheckout::close()` (expire if open; complete =
  never cancel) before `cancelled` or a new token (`restart()`). Webhook
  refuses payments for cancelled purchases.
- Fee: `PLATFORM_FEE_PERCENT` (`App\Support\PlatformFee`), unset = 0 = no
  fee text anywhere (tests check). Fixed on the purchase; own
  `platform_fee` line on the platform account (4th account kind); Stripe
  must receive credits + fee; reconciliation counts fees and checks the
  fee lines. Invariant: paid credits + fees − payouts − held = sum of
  entries. Leave it at 0 unless Finn says otherwise.
- `BuyCreditsFlowTest` is the end-to-end check (fake Stripe, fee off and on,
  reconciliation matching). Local trial: `stripe listen` needs `--events`.

**Merchant data: public business information only — done (2026-10-21).**
Professor Yoest's rule: "public business information only, with no owner
names or personal details." No migration, no prompt, label or lock change;
summary in TRANSPARENCY.md "Merchant data: public business information
only" (keep it true).

- AI reasoning that named people is redacted to "[name removed]" with a
  `redacted` note (shown next to the reasoning). `MerchantOutputPrivacyTest`
  scans every committed merchant output for named individuals: a new
  `merchant:eval` run that names someone must be redacted before committing.
- Candidates: 219 (5 person-named removed; a rebuild skips
  `eligibility.excluded_candidates`, hashes of `MerchantCandidateBuilder::key`).
  `source_url` never asks for `ENTITYNAME`; the points file has no license
  numbers (`fetch-activity` reads them from `source_url`).
- Evidence links: `EvidenceUrl::withoutTracking` wherever they are shown or
  copied into eval outputs. The locked `merchant_labels.csv` keeps 8 links
  with Finn's own tracking IDs, on purpose (lock intact).
- History was not rewritten; never rewrite it (both locks name commits).

**Confirmation toast after every action — done (2026-10-21).** Display
only; no migration, money, ledger, prompt or eval change.

- `<x-flash />` is a toast rendered once by `app-shell` and `layouts/app`
  (not per page). Keys: `status` (✓), `notice` (still waiting) and `error`
  only; never add new flash keys. Wording says the amount, the place and the
  employee by `initials()`. `ActionConfirmationsTest` covers each action.
- Approvals end with `App\Support\PayoutOutcome::sentence()` (by payout
  channel; "paid" only for a sent Stripe transfer). Display only, never
  sends.
- `app.js` focuses the toast after an `#anchor` jump (Chrome clears focus
  then); field errors still win. Checks that ran: `docs/accessibility.md`
  "Confirmation toasts".

**Blind adjudication in batches of 10 — done (ADR 0020).** One migration
(`adjudication_assignments`, additive). Case set, blind view, opening
question and existing votes unchanged.

- `AdjudicationAssigner::nextBatch`: 7 disputed + 3 controls, no repeats,
  fewest votes first within the person's group (headline and personal
  contacts balanced separately), per-person order. `adjudication.more` /
  `adjudication.finish` are on both allowlists.
- A vote counts once its batch is submitted; participants with no
  assignments and `submitted_at` set pressed Submit all before batches and
  all their votes count. `submitted_at` now means finished.
- Results: `vote_count` per case, `*_votes` per figure, fewer than
  `AdjudicationResults::MIN_VOTES` (3) = `too_few`, per group.

**Personal contacts vote in blind adjudication — done (ADR 0019).** No
migration, no money/AI/eval changes; published case set and votes untouched.

- `ReviewerType::PersonalContact` ("Personal contact (knows Finn)"):
  `mayAdjudicate()` (BID, contest judge, personal contact) drives
  `canAdjudicate`; `countsInAdjudicationHeadline()` (BID, contest judge)
  says whose votes make the result. Friend and Public still can't vote.
- `KeepPersonalContactsToAdjudication::ALLOWED_ROUTES`: voting, feedback,
  account, logout, magic link only; `/judge` and `/` redirect to
  `/adjudicate`, everything else 403 (`PersonalContactAccessTest`). A new
  page needs no change; it's refused for them automatically.
- `AdjudicationResults::for()`: `all`/`unseen` = headline only; `personal`
  is separate, numbered on its own. Never add the groups together.
  `AdjudicationSet::submittedCount()` is headline only.
- Admin pages name a personal contact with `User::adminLabel()` /
  `adminEmail()` (initials, no email); never their name anywhere
  (`EmployeeNamePrivacyTest`).

**Security audit and test gaps — done (Prompt 9C, ADR 0018).** No
migration, no money/AI/eval changes. Summary in TRANSPARENCY.md "Security";
keep it true.

- `scripts/scan-history-for-secrets.sh` (`SecretScanScriptTest`, CI
  `fetch-depth: 0`): never prints a value, fails on a shallow clone. Fake
  values in tests must say fake / not-a-real / example, or the scan fails.
- Origin lock: `RequireCloudFrontOrigin` 403s production requests without
  `X-Origin-Verify` (except `/up`); kill switch `ORIGIN_VERIFY_ENFORCE=false`.
  `app:check-production-config` (`ProductionConfig`) runs in the predeploy
  hook before anything else; production forces debug off at boot.
- Rate limits: write routes use `throttle:N,1,<route name>` (plain
  `throttle:N,1` shares one counter per person). Named limiters `redeem`,
  `feedback`, `login`, `auth-forms` (all Fortify posts, via
  `config/fortify.php`). `RateLimitsTest` fails a write route that can reach
  Claude without a throttle.
- `EmployeeNamePrivacyTest` also sweeps as the public judge (names,
  candidates, reviewer names, emails). `MoneyGuardsTest` covers the money
  refusals.

**Accessibility and UX pass — done (Prompt 9B, `docs/accessibility.md`).**
No migration, no money/AI/eval changes.

- `docs/accessibility.md` lists only checks that actually ran (not tested
  with a screen reader or a real phone). Keep it true when adding pages.
- Tests: `AccessibilityTest` (+ `Tests\Support\AccessibilityAudit`) and
  `EmptyStatesTest` sweep every parameterless GET page as every role via
  `Tests\Concerns\SweepsPages` (also used by `EmployeeNamePrivacyTest`);
  `ErrorPagesTest`. New pages are covered automatically and must pass.
- Patterns (all on `/styleguide` → States): `<x-flash />` for every
  status/error message (focus moves to `[data-flash]` after reload; first
  `aria-invalid` field wins); every POST form has `data-busy="…"` (a test
  checks; logout and magic link exempt); wide tables in
  `.scroll-region` with `role="region" tabindex="0" aria-label`; fields
  with a hint/error point at `{id}-hint`/`{id}-error`; animations only
  `motion-safe:`; `x-badge :wrap="true"` for long text.
- Error pages in `resources/views/errors` show detail only for our own 403
  reasons. The admin's new-link flash is `new_reviewer_link`
  (`ReviewerLinks::SESSION_KEY` is the judge fingerprint).

**BID impact dashboard — built (Prompt 9A, ADR 0017).** No migration.

- `App\Services\BidImpactMetrics`: spending = `merchant_earned` ledger
  entries, dated by the redemption's `created_at` in DC time (like
  `EmployerMetrics`). Merchants reached, employees participating (count
  only), employers participating; this month and since launch; 8 Monday
  weeks. Employer rollup = `EmployerMetrics::for()` unchanged. New
  engagement numbers go into `EmployerMetrics` first.
  `BidImpactMetricsTest` checks totals = ledger and BID = sum of HR.
- `/bid-admin/impact` (`bid-admin.impact`, BID admin nav; platform admin
  links from `/admin`, its tab bar is full at 6). Every number has "How we
  count this". Empty state when nothing has cleared (Reset demo reseeds
  history, so it shows only with an empty ledger).
- Recirculation: only as the boxed "Estimate from an outside study, not
  measured by Local Goods", Civic Economics Indie Impact national summary,
  52.3% (config `impact.recirculation`). "Roughly 3x" / "local multiplier"
  only with that label and source (ADR 0024); never a measured result
  (tests check).
- Landing strip: `BidImpactMetrics::totals()`, labelled "Demo data: all
  people and transactions are fictional.", no names.
- Fraud review label "Round amount (a multiple of $10, and at least $20)"
  is built from `fraud.round_number_*` config (`SignalPresenter`); display
  only.

**Fraud gate on Stripe transfers — built (Prompt 8, ADR 0016).** Built only
around the AI: the fraud prompt (`8d5e1e70`), signals, model and eval data
are unchanged.

- `TransferGate::allows` re-reads the row: approved + merchant earning in
  the ledger + (AI cleared itself via `Redemption::wasClearedByAi`, or a
  `redemption_decisions` Approve). Held, denied, unreviewed, seed: never.
  `MerchantPayouts::sendOne` is the only `createTransfer` caller and asks
  the gate first; `FraudGateTest` checks this (source scan + gate shut).
- Decisions: `RedemptionReviewService::approve($r, $user, $reason)` /
  `deny($r, $user, $reason, DenialReason)`. Append-only
  `redemption_decisions`, one per redemption (unique index), reason
  required, keeps the AI's original answer. Deny: `SuspectedAbuse` →
  employer pool (`review_denied_to_employer`), `NoPurchase` → employee
  (`review_rejected`). Stored status stays `rejected`; the UI says
  "Denied". Not eval labels: never read by the fraud eval, `/eval` or
  adjudication. In DEMO_TABLES; `demo:seed-if-empty` ignores it
  (`DemoResetter::EMPTY_AFTER_SEED`).
- Idempotency: `MerchantPayouts::idempotencyKey` (instance, redemption,
  row created, `attempts`); attempts only go up after a definite refusal.
- Audit trail: `Redemption::auditTrail()` on `/bid-admin` and `/merchant`
  (load `decision` and `stripePayout`). Staff by `User::initialsOf`.
- "Run a collusion example" (`bid-admin.collusion-example`,
  `run-collusion-example` gate, throttled): one real AI call, a seeded
  synthetic employee (never Jordan) at Farragut Grind. Hidden from judges.
- Honest limit: the planted collusion patterns look ordinary one
  redemption at a time; catching them needs new signals (new prompt
  version + fresh holdout, Finn's say-so).

**Stripe Connect, test mode — built (ADR 0015, `docs/STRIPE.md`).** Separate
charges and transfers.

- Mode: `App\Support\StripeMode::active()` only with `STRIPE_KEY`,
  `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` all set and test keys; otherwise
  simulated (CI, tests, missing/live keys). Never print, log or commit keys.
  All Stripe calls go through `App\Services\Payments\StripeGateway`; tests
  use `Tests\Concerns\UsesFakeStripe` / `Tests\Fakes\FakeStripeGateway`.
- Purchases: pending until a signed `payment_intent.succeeded`
  (`CreditLedger::markPurchasePaidByStripe`, status `paid`, once). Simulated
  mode keeps `payPurchase` (status `simulated`). Never label one as the other.
- Payouts: two channels that never mix. `StripeFunding::claim` (inside the
  clearing transaction) makes a Stripe payout row only for a cleared
  redemption in Stripe mode covered by a Stripe-paid purchase (oldest first);
  everything else, including all seeded history, stays simulated and is
  never sent to Stripe. `MerchantPayouts::send` runs after commit, only once
  the merchant is onboarded. `TransferGate::allows` is the fraud gate
  (ADR 0016). Ledger payout entries only for `sent`/`simulated` rows.
- Webhook `/stripe/webhook` in `routes/stripe.php`, outside the web group (no
  session/CSRF/user); signature is the only auth; `stripe_events` dedupes
  (not a demo table). The judge sweep skips only `stripe.webhook`.
- Onboarding: Express dashboard on **Accounts v2** (Stripe refuses new v1
  Express accounts here; v2 needs `contact_email`), demo merchants only; GET
  return/refresh pages never create anything; Reset demo keeps
  `stripe_account_id`. `StripeOnboarding::apply` reads v2 accounts and v1
  `account.updated` snapshots. Transfers stay on v1.
- Reconciliation: `/admin/reconciliation`, Stripe-funded activity only,
  this environment only. Every Stripe object we create carries
  `StripeEnvironment::tag()` (`lg_env` + `lg_instance` from `APP_KEY`);
  other-environment and unknown untagged objects go in "From another
  environment, not counted", and the webhook ignores other-env events
  before writing anything. Never suggest refunds without "check before".
- Every Stripe call is wrapped in `NoticeSafeStripeGateway`: stripe-php
  raises Stripe-Notice headers as PHP warnings (a 500 otherwise), and it
  ignores `STRIPE_SUPPRESS_NOTICES` when an AI agent env var (CLAUDECODE) is
  set. Don't bind the SDK gateway unwrapped.

**Money loop screens — built (ADR 0014).** Employer/HR dashboard
(`/employer`, `Employer\EmployerDashboardController`), extended wallet,
merchant dashboard (`/merchant`) and BID participation
(`/bid-admin/participation`).

- Purchases: `CreditLedger::requestPurchase` writes only a `pending`
  `credit_purchases` row, **no ledger entry**; only a paid purchase
  (`simulated` or Stripe `paid`) is in the ledger, once.
  Pending credits can't be allocated or spent. Seeded purchases are
  `simulated` (paid). Ledger invariant counts paid purchases only.
- Allocation from the dashboard: `CreditLedger::allocateMany` (all or
  nothing, checks paid balance and `EmployerProgram::remainingBudgetCents`).
  Occasions: `App\Enums\Occasion` (monthly plus optional welcome, birthday,
  recognition; amounts on `employers`, empty = off).
- Metrics: `App\Services\EmployerMetrics`, cleared redemptions only, calendar
  month in DC time, 8 Monday weeks. Each number on the page says how it's
  counted; keep the code and that text in step.
- Privacy: outside an employee's own wallet show `Employee::initials()` or
  `publicLabel()` ("H.M. · #014"), never `display_name`.
  `EmployeeNamePrivacyTest` sweeps every page as every role.
- Merchant login: `merchants.user_id`, linked to Farragut Grind
  (`demo.accounts.merchant.business`) by `DemoAccountSeeder::linkDemoMerchant`.
- Local trial runs: `php artisan serve` doesn't pass `DB_DATABASE` to its
  worker, so it uses `.env`'s database. Use `php -S` from `public/` with a
  scratchpad SQLite instead.

**Merchant Eligibility Matching — built and measured (ADR 0013).** The AI is
a triage tool and only recommends; a person makes every decision
(`MerchantReviewDecision` refuses anyone but a BID or platform admin). Prompt
frozen at `458e8c2d` (`eligibility.frozen_prompt_version`, CI checks it); the
holdout has been run once with it (0 of 17 chains called eligible, 0 of 3
independents found). A prompt change needs Finn's say-so and a fresh unseen
holdout. Web search is future work.

- Matcher: `App\Services\Eligibility\MerchantEligibilityMatcher`, prompt
  `resources/prompts/merchant-eligibility.md` (rule verbatim; version = first
  8 hex of its SHA-256). Input is only name, address, category and license
  business activity (`data/sources/merchant_candidate_activity.csv`, from
  `eligibility:fetch-activity`); never read the label file in it. Fails
  closed to undetermined + human review; undetermined or confidence < 0.7
  always needs review. The AI has no web access: keep
  `KNOWLEDGE_NOTICE` next to any reasoning shown.
- Eval: `php artisan merchant:eval [--split=dev|holdout] [--dry-run]`.
  Refuses if the label lock doesn't match. Change the prompt using dev only;
  holdout runs once per prompt version (refused after that). Dev and holdout
  never pooled. Always tell Finn the `--dry-run` call count before a real run.
  Writes `docs/evals/merchant-*` and `data/merchant_assessments.json`
  (AI output only, read by the queue; production never calls the API for it).
- Queue: `/bid-admin/merchants`, append-only `merchant_review_decisions`
  (not in DEMO_TABLES). Approving never creates a merchant or transaction.
- Real names: only for admins and named reviewers
  (`User::maySeeCandidateNames`); logged-out visitors and the Public judge
  account see totals only on `/eval/merchants` and the queue.

Done: Prompts 0, S, R, DB, E, 1, 2 and 3, magic links (ADR 0010), blind
adjudication (ADR 0011), the three-account ledger (ADR 0012), Merchant
Eligibility Matching (ADR 0013), the money loop screens (ADR 0014),
Stripe Connect test mode (ADR 0015), the fraud gate on transfers
(ADR 0016, Prompt 8), the BID impact dashboard (ADR 0017, Prompt 9A), the accessibility and UX
pass (Prompt 9B), the security audit (ADR 0018, Prompt 9C) and personal
contacts in adjudication (ADR 0019), one-step Buy credits with the
platform fee ready but off (ADR 0021) and platform-set-up Stripe test
accounts for the demo merchants (ADR 0022), display-only product menus
(ADR 0023), mission-first messaging (ADR 0024), the Transit lines
brand with button states and menus (ADR 0025), paying in person with
pay codes (ADR 0026), the phone-first pass, DC sales tax at
redemption (ADR 0027), refunds and corrections (ADR 0028), the
merchant fee, ready but at 0 (ADR 0029), the business model with
impact projections (ADR 0030) and districts, partnerships and merchant
joining (ADR 0031).
- Ledger (ADR 0012): one append-only ledger, each entry on exactly one
  account (employee, employer or merchant). `App\Services\CreditLedger` does
  purchases, allocations, merchant earnings and payouts. A merchant earns a
  redemption only once it clears, never while held. Invariant: purchases −
  payouts (sent or simulated) − amounts under review = sum of all entries.
  With corrections (ADR 0028): payouts are net of `refund_reversal` lines,
  and employer refunds held or done come off too
  (`MakesCorrections::assertLedgerInvariant`). Seeded payouts are `simulated`.
- Seed: Meridian (120 staff, 40 enrolled). Jordan Rivera must stay the first
  employee with the same 12 redemptions (fraud eval signal test and wallet
  demo depend on it). `DemoHistorySeeder` adds 39 employees, 8 weeks of
  deterministic history at the fictional merchants and two planted collusion
  patterns (documented there). Reset demo clears `DemoResetter::DEMO_TABLES`
  only.
- Real merchant candidates: `data/merchant_candidates.csv` from
  `php artisan eligibility:build-candidates` (DC Open Data licenses + DC GIS
  boundary; kept only if the spatial query, the license's BID field and our
  point-in-polygon check agree). Real candidates must never appear in
  synthetic data or seeds, and any page showing one must label it
  "Candidate — not a Local Goods participant." (`config('eligibility.candidate_label')`).
  Never store owner or agent names.
- Labels: `data/merchant_labels.csv`, 60 rows (40 dev, 20 holdout). Labels
  are Finn's alone: never fill in, suggest or hint at `my_label`,
  `fail_reason`, `evidence_url` or `my_note`. `fail_reason` is one of
  local_chain, franchise, national_chain, outside_control (part 1 of the
  rule). Checked by `App\Services\Eligibility\MerchantLabels`.
- Label lock: `data/merchant_labels_lock.json` (written by `merchant:lock`)
  holds a SHA-256 of every labeled row and labeling commit 30b610e. Never
  edit a locked row (CI fails via `MerchantLabelLockTest`); a deliberate
  change needs Finn's say-so and `merchant:lock --force`.
- Blind adjudication (ADR 0011): `/admin/adjudication` builds a set from the
  latest eval run (every disagreement plus as many random agreed cases as
  blind controls; locked once published, new version to change). Only judges
  with reviewer type BID, contest judge or personal contact (ADR 0019,
  reported separately) vote (`adjudicate` gate, `User::canAdjudicate`) at
  `/adjudicate`; `adjudication.start/vote/submit`
  are on the judge write allowlist. Results (`AdjudicationResults`) on the
  admin page and `/eval#adjudication`; they never change labels or the
  headline. Judges who can't vote get an "Independent check of the AI" card
  on `/judge`; the voting end date it shows is `reviewers.adjudication_ends_at`
  (display only: voting ends when the admin presses Close voting).
- Magic links (ADR 0010, supersedes 0008's invites): judges never create
  accounts or set passwords. `App\Services\ReviewerLinks` makes a reusable
  link `/judge-access#<id>.<token>` (token after `#`, so never in logs; the
  page posts it). Only its hash is stored, compared with `hash_equals`; valid
  until `JUDGING_ENDS_AT` (`config/reviewers.php`, default 2026-11-30 23:59
  America/New_York). Only ever opens a judge account; judges can't log in
  with a password. `EndReviewerSessions` ends a judge session on its next
  request after revoke, New link (session fingerprint mismatch) or the end
  date. In tests, `actingAs($judge)` gives them a link-backed session
  (`tests/TestCase.php`); use `be()` for one without. One shared "Public
  judge access" reviewer (`ReviewerType::Public`, made by
  `demo:seed-accounts`, no password) for the README link; tighter feedback
  limits (`throttle:feedback`). After a new public link, update README's
  "For judges" link.
- 3 (reviewers and feedback): judges are read-only: `BlockJudgeWrites`
  (allowlist: judge-access.store, logout, feedback.store, feedback.update)
  plus gates and policies; `JudgeReadOnlyTest` sweeps every write route, so
  **any new write route must pass it**. Judges can GET the BID admin,
  employer, employee and merchant pages ("view as", `App\Support\ViewAs`)
  but never `/admin/*`, which holds real reviewers' names and emails.
  Feedback button on every signed-in page (`feedback` table), inbox at
  `/admin/feedback`, changelog for reviewers at `/admin/changelog`, "What's
  new" on `/judge`. ADR 0008.
- HTTPS (ADR 0009): CloudFront (`cloudfront.net` address) in front of
  Beanstalk, talking to it over http without the Host header. In production
  `UseAppUrl` pins URLs and the request itself to APP_URL (magic links use
  it too); `TrustCloudFront` believes X-Forwarded-For only with the
  `X-Origin-Verify` secret (`CLOUDFRONT_ORIGIN_SECRET`); session cookie is
  Secure. `BehindCloudFrontTest` covers it. Never trust proxies with `*`.
- 2 (logins and roles): Laravel Fortify login (no registration, 5 tries a
  minute, "contact the admin" instead of reset emails while there is no mail
  service). Six roles in `users.role` (`App\Enums\Role`), each with its own
  dashboard and menu; `role:` middleware and `RedemptionPolicy` (only
  bid_admin and platform_admin approve or reject). Wallet at `/employee`,
  BID admin at `/bid-admin`, both behind login; `/eval` stays public; old
  `/demo` URLs redirect to `/login`. Demo logins come from
  `php artisan demo:seed-accounts` with `DEMO_PASSWORD_*` settings (local
  `.env` and Beanstalk environment properties; never commit or print them).
  The deploy hook runs it after migrating.
- S (October working slice): employee wallet and BID admin view, Claude API fraud check that fails closed (any AI
  failure holds the redemption), append-only credit ledger, deployed to
  Elastic Beanstalk.
- R (repo trophy case): README with live link, stack and rubric table, CI
  badge, `TRANSPARENCY.md`, `CHANGELOG.md`, decision records in `docs/adr/`.
- Production runs on Amazon RDS MySQL.
- Redemption Fraud Detection eval: headline is `holdout_3` (50 cases, 26
  suspicious, locked in `data/fraud_eval_lock.json` at labeling commit
  5f594c1): caught 17 of 26 suspicious; 17 of 19 holds were suspicious.
  Prompt frozen at `8d5e1e70`. Never edit locked holdout_3 rows (CI fails and
  `fraud:eval` refuses); a prompt change needs a fresh unseen split.
- Brand redesign: theme tokens, self-hosted fonts, employer-first landing
  page, app shell, local-only `/styleguide`. Replaced by the Transit lines
  brand (ADR 0025).

Update this section whenever the milestone changes.

### Notes for later

- When real Golden Triangle merchant names are seeded from public records,
  update the footer in `resources/views/layouts/app.blade.php` and
  `tests/Feature/LandingPageTest.php` together. Until then the footer must say
  all businesses are fictional, and the test checks that "public records" does
  not appear.

## What Local Goods is

- Employers inside a Business Improvement District (BID) buy credits.
- Their employees can spend those credits **only at independent merchants in
  that BID**.
- **Stripe Connect (test mode)** holds and moves the money.
- **Local Goods** keeps the entitlement ledger (who is owed what) and enforces
  the rules.

**MVP scope: ONE BID (Golden Triangle, Washington DC) and ONE employer.** Do not
build for multiple BIDs. No tenant switching, no BID selector, no
multi-BID data model.

## Customers, in priority order

1. **Major employers in the BID** (banks, law firms, agencies), through their
   HR departments. They are the buyer and fund the program. Their pitch: a
   perk employees actually use, and a reason for staff to get out of the
   office into the neighborhood.
2. **The Golden Triangle BID.** The channel that introduces us to employers.
3. **Merchants** (restaurants, retail, salons, gyms). They gain incremental
   sales and have almost no barrier to joining.

**The employer/HR experience gets the most polish.**

## Redemption is closed-loop

- The employee's phone is the card. Redemptions happen in the app.
- Merchants are paid out through Stripe Connect.
- **No Visa/Mastercard card program in the MVP.** Do not add card issuing.

## The two named AI functions

1. **Merchant Eligibility Matching.** Decides whether a business in the BID is
   independent and eligible under the rule below, with written reasoning and a
   confidence score.
2. **Redemption Fraud Detection.** Scores every redemption for abuse such as
   credit-to-cash collusion. **It gates real Stripe transfers:** a flagged
   redemption is held and no money moves until it is cleared. It fails closed:
   any API error or invalid reply means hold, never clear. Only anonymous
   signals (no names) are sent to the AI.

Use these exact names in code, UI, and docs.

## Independence rule (verbatim — do not paraphrase in code or prompts)

> A merchant is independent and eligible only if (1) it is owned and operated
> by individuals or a company headquartered in the DC metro area with no
> outside parent, private-equity, or public-company control; (2) it and any
> sister brands have five or fewer locations; (3) it is not a franchisee. If
> ownership can't be established from public sources it is labeled
> undetermined.

## Stack

- **Laravel** — check `composer.json` for the version (currently `^13.17`, PHP `^8.3`).
- **Tailwind via Vite** (`npm run dev` / `npm run build`).
- **Database:** SQLite at `storage/database.sqlite` (gitignored) for local
  development; tests use in-memory SQLite. **Production uses Amazon RDS MySQL**
  (database `local_goods`), configured only through Beanstalk environment
  properties (`DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
  `DB_USERNAME`, `DB_PASSWORD`). CI runs the tests on both SQLite and MySQL.
- **Deploys never wipe data.** The predeploy hook
  (`.platform/hooks/predeploy/01_database.sh`, also run on config changes via
  `.platform/confighooks/`) checks the DB_ settings, then runs `migrate --force`,
  `demo:seed-accounts` and `demo:seed-if-empty`. It runs before PHP restarts, so a failure stops the
  deployment and the running site is unchanged. Never cache config in it.
  Never put `migrate:fresh`, `db:wipe` or `--seed` back in it
  (`tests/Unit/DeployHookTest.php` enforces this). Every migration must work on
  both SQLite and MySQL. Reset demo clears only `DemoResetter::DEMO_TABLES`.
- **Claude API** via the official `anthropic-ai/sdk` package, wrapped in
  `App\Services\Ai\ClaudeClient`. Key from `ANTHROPIC_API_KEY`; model from
  `ANTHROPIC_MODEL` (default `claude-opus-5-5`). Prompts live in `resources/prompts/`.
  Tests use `Tests\Fakes\FakeClaudeClient` and never call the real API.
- **AWS Elastic Beanstalk**, PHP platform, single instance. Config lives in
  `.ebextensions/`. EB runs `composer install` on deploy, so `vendor/` is not
  shipped.
- **Deploying:** run `scripts/build-deploy-zip.sh`, then upload the resulting
  `deploy.zip` in the Elastic Beanstalk console. **There is no auto-deploy
  from GitHub.** Laravel's error log (`storage/logs/*.log`) is included in the
  console's Logs → Request logs downloads (`.ebextensions/laravel-logs.config`).
- Live URL is in `README.md`.

## Deploy checklist

Follow these in order for every deploy. **Whenever you build a deploy zip
for Finn, repeat this checklist in your final message**, and call out the
snapshot step by name when the build says the deploy includes migrations.

1. Stop `composer dev` (Control+C) before building the zip
   (`scripts/build-deploy-zip.sh`).
2. Confirm CI is green on `main` for the commit being deployed (both the
   SQLite and the MySQL job).
3. If this deploy includes any new migration: take a manual RDS snapshot
   first (RDS → local-goods-db → Actions → Take snapshot, named
   `before-<short-feature-name>`) and wait until it shows Available. The
   build ends by listing the migrations since the last deployed commit, or
   saying "No migrations in this deploy." (`scripts/deploy-migrations.sh`;
   a reminder only, it never blocks the build).
4. Origin lock (ADR 0018): Beanstalk must have `CLOUDFRONT_ORIGIN_SECRET`,
   and the CloudFront origin must send the same value as its custom header
   `X-Origin-Verify`. The deploy stops ("CLOUDFRONT_ORIGIN_SECRET is empty")
   if the secret is missing while `ORIGIN_VERIFY_ENFORCE` is on (default),
   and also if `APP_DEBUG` is true, `APP_ENV` isn't production or
   `SESSION_SECURE_COOKIE` isn't true (`app:check-production-config`).
   **Kill switch:** if the live site returns a bare "Forbidden" through
   CloudFront, set the Beanstalk environment property
   `ORIGIN_VERIFY_ENFORCE` to `false` (a configuration change, no
   redeploy), fix the header, then set it back to `true`.
5. Upload `deploy.zip` in Elastic Beanstalk with the next version label and
   wait for green. Then run `scripts/mark-deployed.sh` to record the commit
   the zip was built from in `.last-deploy-commit` (git-ignored).
6. After deploy: open the CloudFront site and check that the raw
   `…elasticbeanstalk.com` address returns "Forbidden". Log in as each demo role, check
   `/eval` and `/eval/merchants`, press Reset demo, then re-seed AI examples
   as Jordan at Farragut Grind (2 normal, 1 suspicious left held).
   If any demo merchant shows "Not connected to Stripe" on
   `/admin/reconciliation`, press "Connect demo merchants to Stripe (test
   mode)" (Reset demo keeps existing accounts, so usually only once).
   With Stripe keys set: the employer page shows "Stripe test mode", and
   `/admin/reconciliation` says everything matches. As the BID admin, press
   "Run a collusion example" once (one AI call) and check its path line.
7. Never click New link on the live public judge or named reviewer rows.

## Contest

CUA Busch School AI Vibe Coding Contest, judged the **week of Nov 23, 2026**.

| Criterion | Points |
|---|---|
| Functionality | 25 |
| Problem | 20 |
| AI | 20 |
| Code quality / GitHub | 15 |
| UX | 10 |
| Business impact | 10 |
| Bonus | Accessibility and ethical AI |

Keep accessibility (labels, contrast, keyboard use) and ethical AI (explain
AI decisions, let a human override, show confidence) in mind on every screen.

## Hard rules

- **Never commit secrets:** `.env`, API keys, `.pem`/`.key` files, or
  `deploy.zip`. Check `git status` before committing.
- **All demo people and transactions are synthetic.** Never use real
  employee names, real card data, or real customer records.
- **Small commits** with descriptive messages after every working change.
- **Write or update tests for business logic** (ledger, eligibility, fraud
  rules, payouts). Run `php artisan test` before committing.
- **Explain changes in plain English.** The owner is a beginner: say what
  changed, why, and how to check it worked, without jargon.
- **Ask before deleting files or adding paid services.**
- **Never touch a real database without asking first.** Never delete,
  overwrite or re-create `storage/database.sqlite` or any other database
  (local, RDS or otherwise) without asking Finn first. Never run
  `migrate:fresh`, `migrate:reset`, `migrate:refresh`, `migrate:rollback`,
  `db:wipe`, `db:seed`, Reset demo or anything similar against one, and
  don't use `--env` or `DB_*` overrides as a workaround. If you need a
  database to try something, make a new SQLite file in the scratchpad and
  point `DB_DATABASE` at it. `php artisan test` is fine: tests use an
  in-memory database.
