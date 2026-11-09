# Redesign rules (direction A, "Wayfinding")

The redesign in ADR 0038 is built in four batches on the `redesign-a`
branch. Every batch follows these rules. The look comes from the comp in
`.impeccable/comps/a-wayfinding/` (light) and `.impeccable/comps/c-night-line/`
(the night palette used for dark mode).

## 1. Content is locked

- No wording changes to existing text, labels, links, honesty lines,
  numbers, nav or button names.
- New decorative elements (stop numbers, map labels) are `aria-hidden` or
  plainly factual, and add no claims.
- Never imply a BID partnership or endorsement.
- Demo merchants on any map are labelled as demo.

## 2. Images and outside services

- No stock photos and no AI-generated images.
- No external image, map or font services. SVG and CSS only.
- Fonts are self-hosted from npm packages (`@fontsource-variable/public-sans`,
  `@fontsource-variable/overpass-mono`), bundled by Vite.

## 3. Accessibility

- WCAG AA for every text pair. Each new pair goes in
  `App\Support\ColorContrast::PAIRS` and is checked light and dark.
- 3px visible focus rings; 44px tap targets; inputs at least 16px.
- `prefers-reduced-motion` is respected (nothing moves for people who ask
  for less motion).
- Real headings and landmarks.
- Honesty text never sits in a container that is hidden on phones.

## 4. What a batch may change

- Only views, CSS, Blade components, fonts, presentation-only JavaScript and
  tests.
- Never money logic, routes, controller behaviour, the fraud prompt, AI
  inputs, eval data or anything frozen or locked.

## 5. Tests

- The full suite runs every batch.
- Brand or markup tests that pin old fonts or classes may be updated; each
  change is listed with its reason.
- Never weaken `PublicClaimsTest`, `OneMinuteSummaryTest`, or the
  accessibility, contrast, privacy or honesty tests.

## 6. Local server for screenshots

- A scratch SQLite file in the scratchpad, set with environment overrides
  only. No `.env` edits.
- Stripe and Anthropic keys blanked, `MAIL_MAILER=array`, no scheduler.
- Laravel's `server.php` router. From the project root:
  `php -S 127.0.0.1:8000 -t public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`.
  Or `cd public` first and use `php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`.
  Don't use `php artisan serve` (it ignores the scratch `DB_DATABASE`). Both forms were checked: `/up` answers 200.
- Stop the server and delete the scratch database afterwards.
- Never commit `public/build`.

## 7. Every batch

- Screenshots at 1440px and 390px of the changed pages.
- Check `scrollWidth` at 390px (no sideways scrolling).
- Look at the images, not just the numbers.
- Show `git diff --stat`.
- Don't commit until Finn approves.

## Notes found while building

- The logo files stay exactly as they are. `<x-logo>` draws the logo with
  color tokens, so the tokens it reads keep the logo files' exact values:
  light `primary` #0a6b67, `ink` #121820, `accent` #e2603a (from
  `docs/brand/transit/logo-a.svg`); on dark `night-primary` and dark-mode
  `primary` #42c4b9, `on-night` and dark-mode `ink` #edf1f4, `night-accent`
  and dark-mode `accent` #ff8a63 (from `logo-a-dark.svg`). Where the comp's
  value differs by a hair (#151b21 ink, #45c6ba night teal, #eef2f5
  on-night) the logo file wins. `WayfindingFoundationTest` checks this.
- `PhoneLayoutTest` pins the `text-title` size and the footer and tab bar
  classes for phones; keep them.
