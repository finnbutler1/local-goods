# Accessibility and UX check

What was checked on every page of Local Goods, what was fixed, and what
was not checked or could not be fixed. Done on 2026-10-19 (Prompt 9B).
This page lists only checks that actually ran.

## How it was checked

There are two kinds of check: automated tests that run in CI on every
push, and a one-off browser audit run locally for this pass.

### Automated tests (in the repo, run in CI)

| Test | What it checks |
|---|---|
| [`AccessibilityTest`](../tests/Feature/AccessibilityTest.php) | Opens every page whose address has no parameter, signed out and as each of the six roles, plus a judge viewing as each role, the reset-password page, the old invite link, an adjudication case and `/styleguide`. Each page goes through [`AccessibilityAudit`](../tests/Support/AccessibilityAudit.php): `<html lang>`, a `<title>`, exactly one `<main>` and one `<h1>`; every `<img>` has `alt`; every input, select and textarea has a label; every button, link and `<summary>` has a name; every `aria-describedby`/`aria-labelledby` points at an id that exists; no duplicate ids; no positive `tabindex`; every `<svg>` is either hidden or a named image; every table has a caption; radio groups sit in a `<fieldset>` with a `<legend>`; a field with a hint or error points at it; an invalid field points at its message; a box that scrolls sideways can be focused and has a name. |
| Same file, forms with errors | Sends ten forms with bad input (log in, buy credits, give credits to one person and to several, program setup, redeem, deny without a reason, feedback, change password, invite a reviewer), checks a field is marked invalid, and runs the same audit on the page that comes back. |
| Same file, other rules | `prefers-reduced-motion` turns off animations and transitions in `app.css`, and every animation in a view is `motion-safe:`; every form that posts has a "working…" label (`data-busy`); both charts keep their summary sentence and their table. |
| [`ToastTest`](../tests/Feature/ToastTest.php), [`ActionConfirmationsTest`](../tests/Feature/ActionConfirmationsTest.php) | The confirmation toast (`x-flash`): status and notice are `role="status"` (polite), errors `role="alert"`; each has a "Dismiss message" button; only status and notice may fade; the error comes first so it gets focus; it appears once, before `<main>`. Every action (give credits, buy and pay, Checkout return, redeem approved or held, approve and deny, merchant decision, feedback, adjudication, Stripe onboarding) shows its toast with the amount and the employee's initials, never a full name. |
| [`EmptyStatesTest`](../tests/Feature/EmptyStatesTest.php) | With only the BID in the database (no employer, merchants or redemptions), every page as every role either opens or refuses politely (never a server error), and every page that opens passes the audit above. |
| [`ErrorPagesTest`](../tests/Feature/ErrorPagesTest.php) | 403, 404, 419, 429, 500 and 503 each get a plain-English page that passes the audit; our own reasons ("Your account is read-only.") are shown, framework messages are not. |
| [`ColorContrastTest`](../tests/Unit/ColorContrastTest.php) (already there) | Every text and background color pair the design allows is at least 4.5:1 (WCAG AA). |

No accessibility test is skipped or marked as a known failure.

### Browser audit (run once, locally, not in CI)

- **Setup.** The app ran on `php -S` against a new SQLite file in a
  scratch folder, seeded with the demo data (the real
  `storage/database.sqlite` was not touched). Stripe and the Claude API
  were switched off, so payments were simulated and every new redemption
  was held for review (the AI check fails closed without a key).
- **Tool.** Google Chrome 155 (headless), driven by puppeteer-core 24.43,
  with axe-core 4.14 running the WCAG 2.0, 2.1 and 2.2 A and AA rules plus
  axe's best-practice rules. Every `<details>` was opened first so
  collapsed content was checked too.
- **Pages.** 33 pages (34 visits: `/account` as two roles): the landing page, `/eval`, `/eval/merchants`,
  log in, forgot password, the magic-link page, the old invite link, a
  404, and every signed-in page for the employee, employer admin,
  merchant, BID admin, platform admin and judge (including the judge
  viewing as each role), plus `/user/confirm-password` and
  `/styleguide`.
- **Widths.** 375px (iPhone) and 1280px (laptop), then 320px, the width
  WCAG's reflow rule uses (1280px at 400% zoom). Each page was checked
  for horizontal overflow, and full-page screenshots at 375px were looked
  at for cramped or cut-off content.
- **Keyboard.** A script walked eight flows with keys only (Tab,
  Shift+Tab, Enter, Space, arrow keys, typing; on a Mac, a dropdown is
  chosen by typing the start of an option). At every stop it recorded
  whether a focus outline was visible, whether something else covered the
  focused element, and whether it was on screen. It ran at 1280px and at
  375px.
  1. Log in (a wrong password, then the right one)
  2. Skip link (Enter on "Skip to content", then Tab)
  3. Buy credits (a bad amount, then a good one); then it was the two-step
     request and Pay now, since replaced by one step (see "Buying credits")
  4. Give credits to one person
  5. Redeem credits
  6. Fraud review: Deny with no reason (stopped by the browser), Approve
     with a reason, Deny with no denial reason picked (stopped by the
     server), then Deny properly
  7. Feedback widget: open, fill in, Escape, reopen, send
  8. Magic-link landing (with and without the part after `#`), and an
     adjudication vote (Start, then Save and next on several cases)
- **Reduced motion.** Chrome's `prefers-reduced-motion: reduce` was turned
  on, and the Feedback button's fade went from 0.15s to effectively none.
- **"Working…" state.** The redeem request was slowed down on purpose: the
  button read "Checking with Redemption Fraud Detection…", was marked
  `aria-disabled`, the status was announced in a live region, a second
  Enter was ignored, and exactly one redemption was saved.

## What was found and fixed

| Found | Fix | Commit |
|---|---|---|
| `/admin/reviewers` was 714px wide on a 375px phone: a screen-reader-only label inside the scrolling table escaped its box | Wide tables now scroll inside a `.scroll-region` (`position: relative`) | 4349e39 |
| Tables that scroll sideways could not be reached by keyboard (axe `scrollable-region-focusable`, 6 pages, plus the false positive under Known limits) | Each one is a named, focusable region (`role="region"`, `tabindex="0"`, `aria-label`) | 4349e39 |
| Button names that didn't start with their visible text (axe `label-content-name-mismatch`): "Needs research" on the merchant queue, "New link" on reviewers | Names now begin with the visible words | 4349e39 |
| At 375px, keyboard focus landed on fields hidden behind the bottom tab bar or Feedback button (14 tab stops, some repeated, in 5 of the 8 flows) | `scroll-padding` keeps focus clear of the top bar and, on phones, the tab bar | 4349e39 |
| No reduced-motion handling beyond the wallet's pulsing dots | `prefers-reduced-motion: reduce` turns off all animations and transitions | 4349e39 |
| After a form error, focus went back to the top of the page | Focus starts on the first field with an error | c1c27f5 |
| After an action, the result ("Paid", "Approved", "Denied") wasn't announced: messages were rendered six different ways, some not in a live region, and a live region doesn't speak on a new page anyway | One `x-flash` component; focus moves to the message (or to the redemption confirmation), so screen readers read it and Tab continues from there | c1c27f5 |
| Errors not tied to their fields: give credits, deny reason, feedback, change password, adjudication answers, reviewer type | `aria-invalid` plus `aria-describedby` on each field or radio group | c1c27f5 |
| No loading state on slow submits (redeem and "Run a collusion example" wait for the AI); a double press could send twice | Every form that posts shows what it is doing and ignores a second press (what is sent is unchanged) | c1c27f5 |
| Adjudication: each "Save and next" sent keyboard users back past 32 case-number links | Focus moves to the new case's heading: 3 Tab presses per vote instead of about 40 | c1c27f5 |
| Error pages were the framework's bare defaults | Plain-English 403, 404, 419, 429, 500 and 503 pages with a way back; the 500 page says to check before retrying a payment | 4790ea7 |
| `/user/confirm-password` was a server error for any signed-in user (a login-library page that had no design) | A simple page in the login style | 458f89d |
| `/admin/reviewers` crashed in a browser that had opened a judge link before logging in as platform admin (two things shared one session key) | Separate key; regression test | 83353e6 |
| BID admin "Recent redemptions" showed nothing at all when empty | "No redemptions yet." with what will appear | 458f89d |
| The BID impact chart had no text summary (the employer chart did) | One-line summary (this week, and the peak week) next to the table | 9064eb0 |
| `/bid-admin/participation` called measured spending "Dollars recirculated"; ADR 0017 keeps "recirculation" for the outside estimate | "Spent at independent merchants" | 9064eb0 |
| Wallet: merchant names cut off ("Connecticut Noo…") next to a Denied badge at 375px | Names wrap | 7d64000 |
| Impact: the employer table squeezed to one word per line at 375px | Readable column widths; the table scrolls sideways | 7d64000 |
| Deny-reason radio buttons were the browser's tiny default | Same size as every other radio | 7d64000 |
| The 404 page showed the framework's message ("The route … could not be found") | Extra detail only for our own 403 reasons | 7d64000 |
| `/bid-admin/merchants` was 30px too wide at 320px (a long badge that couldn't wrap) | `x-badge :wrap="true"` | e19f070 |
| Every new pattern needed a home | `/styleguide` has a States section: result messages, busy button, empty state, errors | 7d64000 |

The `/admin` accounts table also got its missing caption (80e1157).

## Results after the fixes

- **axe:** no violations on any of the 33 pages at 320px, 375px or
  1280px, except one false positive (see Known limits). This was the
  last run, on the final code.
- **Overflow:** no page is wider than the screen at 320px or 375px.
- **Keyboard:** all eight flows completed with keys only at 1280px and
  375px, with a visible focus outline at every stop and nothing covering
  the focused element.
- **Tests:** all pass (`php artisan test`).

## Pages checked

Signed out: `/`, `/eval`, `/eval/merchants`, `/login`,
`/forgot-password`, `/reset-password/{token}` (tests only),
`/judge-access`, `/invite/{token}`, error pages.

Employee: `/employee`, `/account`. Employer admin: `/employer`,
`/account`. Merchant: `/merchant`, `/merchant/stripe/refresh`,
`/merchant/stripe/return`. BID admin: `/bid-admin`, `/bid-admin/eval`,
`/bid-admin/participation`, `/bid-admin/impact`, `/bid-admin/merchants`.
Platform admin: `/admin`, `/admin/reviewers`, `/admin/reconciliation`,
`/admin/feedback`, `/admin/changelog`, `/admin/adjudication`,
`/user/confirm-password`, `/styleguide`. Judge: `/judge`, `/adjudicate`
(start, cases, submit), view as employee, employer admin, merchant and BID
admin. Every signed-in page: the Feedback button and its dialog.

## Confirmation toasts

After every action, one toast at the top of the screen says what happened
(see `/styleguide` → States). Checked in headless Chrome (a script, on a
scratch SQLite database, simulated payments, no AI key) at 1280px and
375px, with and without emulated `prefers-reduced-motion: reduce`:

- It sits 12px from the top, centered, 16px from each edge at 375px, and
  the page doesn't scroll sideways, even when the redirect jumps to an
  `#anchor` far down the page.
- It takes focus after the page loads. This needed a fix: Chrome clears
  focus when it jumps to an `#anchor` after load, so on those redirects
  focus now moves once the jump is done.
- Escape and × remove it, and focus moves to the page's own answer (the
  redemption card on the wallet) or the `#anchor` section, never `<body>`.
- Without reduced motion, a status toast was gone 9 seconds later and focus
  had moved to the `#anchor` section. With reduced motion it was still
  there after 9.5 seconds.
- A form sent with a bad amount still put focus on that field, not the toast.

Not checked: hovering to pause the fade, and how a screen reader actually
reads it (see Not tested).

## Buying credits

One-step Buy credits with Stripe's embedded Checkout (ADR 0021). Checked in
headless Chrome (a script, on a scratch SQLite database, Stripe test keys,
`stripe listen` forwarding the webhook) on 2026-10-21:

- **Paying state:** Stripe's form mounted inside the Buy credits card, and
  the "didn't start" panel stayed hidden. At 375px the page didn't scroll
  sideways (page width 375) and the form fit the card.
- **Paying with the test card** returned to the page; the webhook had
  already arrived, so the green toast showed straight away. "Confirming with
  Stripe…" was **not seen in the browser** (the webhook was faster); its
  markup is checked by the tests.
- **Cancel payment:** the amber toast took focus, and the form came back
  with the amount filled in and its button reading "Try again".
- With `PLATFORM_FEE_PERCENT=2.5`, the "Credits … + platform fee … = total
  …" line updated as the amount was typed and showed again above Stripe's
  form.
- The paying, confirming and cancelled states pass `AccessibilityAudit` in
  `BuyCreditsFlowTest`. The status panel is `role="status"`, takes focus
  after the return like a toast, and its spinner only turns with
  `motion-safe:`.

Not checked: moving through Stripe's own form with the keyboard or a
screen reader (it is Stripe's, inside their frames), and the 2-minute
"still waiting" text.

## Loading states

Every page is rendered on the server, so nothing loads in pieces after the
page appears, except Stripe's card form while buying credits ("Loading
Stripe's payment form…" until it appears). The only waits are after pressing a button that posts a
form, and each of those now has a "working…" state (see above). There is
no separate loading screen.

## Redeeming from a menu

The two-step redeem (choose a merchant, then pick from its menu) and
Priya's `/merchant/menu`. Checked in headless Chrome (a script, on a
scratch SQLite database, simulated payments, no AI key) at 375px:

- No sideways scroll on the wallet (both steps and the confirmation),
  `/merchant`, `/merchant/menu` and `/bid-admin`.
- Tab from "Change merchant" went − , quantity, + for each product in
  menu order. Enter on "One more Latte" twice set it to 2, and the total
  read $15.75 with one croissant. The total is an `aria-live="polite"`
  region.
- The confirmation card, the toast, the merchant dashboard and Fraud
  review all listed "2× Latte, 1× Croissant".

Each quantity field has its own label ("Latte") and the − / + buttons say
which product ("One more Latte"). They are hidden without JavaScript,
where the quantity fields still work and the server adds up the total.
The static checks (`AccessibilityTest`) cover the menu step, the
suspicious example, and both forms' error states. Not checked: 320px, axe,
and how a screen reader reads the running total (see Not tested).

## Mission rewrite (2026-10-23)

The new landing page (hero, "The commute-desk-commute loop", "Why it
matters" and the labelled 3x box) and the 3x line in the estimate box on
`/bid-admin/impact`. Checked in headless Chrome (puppeteer-core 24.43,
axe-core 4.14, WCAG 2.0/2.1/2.2 A and AA plus best practice, every
`<details>` opened) on a scratch SQLite database with simulated payments
and no AI key, at 320px, 375px and 1280px:

- axe: no violations on `/` signed out or on `/bid-admin/impact` as the
  BID admin, at any of the three widths.
- No sideways scroll on either page at any width.
- Heading order: one `h1` per page and no skipped levels (landing: h1, then
  h2 for each section and h3 for each card; impact: h1 then h2s).
- New text uses only token pairs already checked by `ColorContrastTest`
  (ink, muted and forest on forest-tint and cream-deep; amber-ink on cream).
- The 375px full-page screenshot of the landing page was looked at: the
  cards stack in order (district, employers, merchants, employees, BID)
  with the dashed estimate box after them.

Not checked this time: keyboard-only walk-through (no new controls were
added) and `/judge` in the browser (judges sign in by magic link; the page
is covered by `AccessibilityTest`).

## Transit lines brand (2026-10-23)

The new brand (ADR 0025): light and dark tokens, the theme toggle, button
states and the new menus. Checked in headless Chrome 155 over the DevTools
protocol (a script, axe-core 4.10.2 with WCAG 2.0/2.1 A and AA plus best
practice) on a scratch SQLite database with simulated payments and no AI
key, with dark mode set through the system setting:

- **No sideways scroll and no axe violations** at 375px and 1280px, light
  and dark, on `/`, `/employee`, `/merchant`, `/bid-admin`,
  `/bid-admin/impact` and `/judge` (24 page checks), and on every other
  role page at one width and theme each. The one note left is axe's
  best-practice `landmark-complementary-is-top-level` on the landing page's
  labelled 3x box, an `<aside>` inside a section. It is not a WCAG failure,
  and the claims tests find the box by that tag, so it stays.
- **Contrast:** every approved pair passes in both modes
  (`ColorContrastTest`, 84 checks).
- **Menus by keyboard** (script pressing keys): on the BID admin's phone
  tab bar, ArrowDown opened More on its first item, arrows moved and
  wrapped, Escape closed it and put focus back on More, Enter opened it,
  Tab out closed it. On the platform admin's Account menu: Enter opened it,
  Escape closed it, ArrowUp opened it on "Log out". Clicking elsewhere and
  opening another menu closed it. axe found nothing with menus open.
- **No double submit:** three clicks on "Save program" sent one request.
  30ms after the click the button showed the spinner and "Saving…", with
  `aria-busy` and `disabled`.
- **Theme toggle:** pressing it gave dark with `aria-pressed="true"`, and a
  reload kept it.
- **Digits:** the 375px light and dark screenshots of the wallet were
  looked at; Overpass digits in Atkinson text read as one font, with plain
  zeros and aligned amounts.

Not checked this time: the menus in Safari (it does not focus clicked
links; app.js has a guard for that, untested), 320px, and the adjudication
voting page in the browser (covered by `AccessibilityTest`).

## Pay in person (2026-10-23)

The wallet's two tabs, the code screen and the merchant's Take a payment
card ([ADR 0026](adr/0026-pay-in-person-with-pay-codes.md)). Checked in
headless Chrome (a script driving it over the DevTools protocol, on a
scratch SQLite database, simulated payments, no AI key, so the payment was
held) at 375px:

- No sideways scroll on the wallet (both tabs, the menu step and the code
  screen), the merchant dashboard, the review card and the held result.
- The code "MPY 4ZF" fit inside its card at 375px, and the countdown went
  from 9:58 to 9:56 two seconds later.
- Keyboard, by key events in the script: with focus on "Order to go",
  ArrowRight moved focus to "Pay in person", set `aria-selected="true"`,
  showed its panel, hid the other and changed the address to
  `?tab=in-person`; Home went back. End was pressed but not checked.
- "One more Latte" pressed twice made the in-person total $11.50; the
  review card read "Amount $11.50 Items 2× Latte Customer J.R.".
- Screenshots of the code screen in light and dark, and of the merchant's
  held result, were looked at.

Static checks (`AccessibilityTest`): the in-person menu step, the code
screen, the judges' sample screen, the cashier's review card, the error
states of both new forms, and (in `HeldPaymentCancelTest`) the held
screens with Cancel this payment. Tests check that the code is given to
screen readers one character at a time, that the countdown is hidden from
them with a fixed "Expires at" time instead, and that the script speaks
only twice (one minute left, expired). Not checked: how a screen reader
actually reads the tabs, the code or the announcements, 320px, and axe on
these screens.

## Phone-first pass (2026-10-24)

Every page made to work on a phone first, desktop unchanged.

**How it was checked.** A Playwright script (installed in a scratch
folder, not in `package.json`) drove headless Chrome against `php -S` on
a scratch SQLite database with the demo data, simulated payments and no
AI key. Every page with no parameter in its address was opened signed out
and as each of the six roles, at 320px, 375px and 414px wide, and
screenshotted in light and dark. Extra states: the wallet's menu step and
a live pay code, the cashier's Take a payment card before and after
looking up that code, reviewers (three fictional ones added to the
scratch database) and reconciliation. Playwright's WebKit (the engine
inside Safari) ran the public pages, the wallet, the merchant dashboard,
the judge pages, both eval pages and the same states at 375px.

For each page the script measured:

- sideways scroll (page wider than the screen);
- tables wider than their box;
- words split across two lines (a run of five or more letters or digits,
  not at a hyphen or slash, whose letters sit on two lines);
- controls under 44 × 44px (links inside a sentence don't count, as in
  WCAG 2.5.8; a checkbox or radio is measured with its label);
- the last content ending less than 34px above the tab bar (a simulated
  iPhone home indicator; content scrolled inside its own box doesn't
  count);
- fields under 16px (iOS zooms in on them);
- things shown only on hover.

**Results** (counts of page, role and width combinations; distinct words
or controls for "split" and "under 44px"):

| | Chrome before | Chrome after | WebKit before | WebKit after |
|---|---|---|---|---|
| Sideways scroll | 21 | 0 | 0 | 0 |
| Tables cut off | 70 | 0 | 35 | 0 |
| Words split mid-word | 10 | 0 | 1 | 0 |
| Controls under 44px | 484 | 0 | 73 | 0 |
| Content behind the tab bar | 0 | 0 | 0 | 0 |
| Hover-only | 5 | 0 | 0 | 0 |
| Fields under 16px | 0 | 0 | 0 | 0 |

The sideways scroll was the public header at 320px; most small controls
were the 32px "sm" buttons (about 240 on the merchant candidate queue)
and the employer's employee checkboxes. Nothing was hidden behind the tab
bar before either, but the padding didn't allow for the home indicator; it
does now (`env(safe-area-inset-bottom)`). WebKit drew dropdowns at 28px
whatever their padding, so on phones they are now drawn by us.

**Desktop.** Every page as every role at 1280px was screenshotted on the
commit before this pass and after it, and compared pixel by pixel. Public
pages match exactly. On signed-in pages the only differences are
sub-pixel letter spacing in emails (around the `<wbr>` breaks) and, in
full-page screenshots only, the Feedback button fading as the window is
resized; a normal 1280 × 900 screenshot of `/employer` matched exactly.

**Tests added:** `ResponsiveTablesTest` (every table on every page, as
every role, uses the card pattern with a label on each cell),
`PhoneLayoutTest` (no `break-all`; emails break only between their parts;
safe-area padding; 44px rules; 16px fields and the right keyboard for
money, email, quantity and pay code fields; nothing hover-only; titles
scale) and `PhoneHonestyTest` (demo-data, not-affiliated, 3x evidence,
money-mode, read-only and Stripe test-mode lines are never hidden on
phones). `AccessibilityTest` still passes on every page.

**Screen readers and the card tables.** Below 640px a table's rows are
drawn as cards, but it stays a real `<table>` with its header row (visually
hidden), and `app.js` adds table roles because Safari otherwise stops
treating it as a table. Not checked: how VoiceOver actually reads the
cards (whether it reads the column name twice, from the header and the
on-screen label), and axe was not re-run in this pass.

## Not tested

- **Not tested with a screen reader.** No VoiceOver, NVDA or JAWS session
  was run. Names, labels and announcements were checked from the HTML and
  with axe, which is not the same as listening to the pages.
- **Not tested on a real phone.** Phone widths were Chrome's emulation of
  a touch screen at 320px, 375px and 414px, and Playwright's WebKit (the
  engine inside Safari) at 375px, not an iPhone or Android device. The
  home indicator and notch were not seen: `env(safe-area-inset-*)` is 0
  in both, so the safe-area padding was checked in the HTML and with a
  simulated 34px indicator, not on a device.
- **Not tested by a person with a keyboard.** The keyboard walkthrough was
  a script pressing keys in headless Chrome.
- **Not tested with browser zoom or larger text settings**, other than the
  320px width check that WCAG's reflow rule treats as equal to 400% zoom.
- **Not tested in Windows High Contrast (forced colors) mode, or in
  real Safari, Firefox or Edge.** WebKit was run headless through
  Playwright for the phone-first pass only.
- **Stripe mode was only partly audited.** The 9B browser audit ran in
  simulated mode. Buying credits in Stripe test mode was checked later (see
  "Buying credits"), but the onboarding buttons and the reconciliation page
  with real Stripe data were not. Stripe's own Checkout form and Express
  onboarding pages are Stripe's and were not checked.
- **The pulsing dots on an approved redemption** were not seen in the
  browser (without the AI key every new redemption is held). They use
  `motion-safe:`, and a test checks that every animation in the views
  does.

## Known limits

- **axe false positive on the read-only employee list.** When a judge views
  the employer page, the "Choose people" list sits in a disabled form, so
  its checkboxes can't take focus. The list itself has `tabindex="0"` so it
  can still be scrolled by keyboard, and the script confirmed Tab reaches
  it. axe still reports `scrollable-region-focusable` because it treats
  everything inside a disabled `<fieldset>` as unfocusable.
- **axe could not decide on some contrast.** On most pages axe marked a few
  text elements (up to 31 on the busiest page, the employer dashboard) as "needs review", because
  the Feedback button, the tab bar or a decorative element overlaps them in
  the screenshot, or the element holds only a symbol such as an arrow. It
  found no contrast failures. All text colors come from token pairs that
  `ColorContrastTest` checks at 4.5:1 or more.
- **Chart bars are for mouse and touch.** The bars are hidden from screen
  readers and show their week's numbers on hover or tap, not on keyboard
  focus. Everyone gets a one-line summary under each chart and every
  number in "Show as a table".
- **The "working…" state needs JavaScript.** Without it, forms still work
  but the button doesn't change while the next page loads, and the menus
  show their lists open in the page.
- **The Feedback button steps aside** (fades out) while it would cover a
  field or while someone is typing. That is on purpose, but on a phone it
  can be out of sight while a form is in use.
