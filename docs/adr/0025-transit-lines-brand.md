# 0025. Transit lines brand, Logo A, visible button states and disclosure menus

**Status:** Accepted, implemented. Replaces the Storefront look (forest,
cream and amber; Fraunces and Public Sans; the awning stripe).

## Context

Step 2 of the October 23 plan put three original brand directions side by
side in `docs/brand/directions.html`. Finn chose **Transit lines** with
**Logo A, "The daily loop"**: the commute-desk-commute loop, rerouted
through one local stop. It reads as a serious payments product that still
loves the corner café, and it says the mission in one picture.

Two usability gaps were fixed at the same time: buttons did not show every
state (pressed and unavailable looked alike, and unavailable was 50%
see-through), and the signed-in chrome repeated the same account actions in
three places while the platform admin's phone tab bar held six items.

## Decision

- **Tokens by role, light and dark.** `resources/css/app.css` `@theme`
  holds the light values of `page`, `surface`, `band`, `ink`, `muted`,
  `line`, `edge`, `primary` (+ hover, pressed, on-primary, tint), `accent`
  (+ on-accent, accent-ink), `ok`, `hold`, `danger`, `focus`, `disabled-*`
  and the `night` panel set. Dark mode redefines the same variables for the
  system setting or the header toggle, so every class switches by itself.
  The old names are gone. `ColorContrastTest` checks every approved pair in
  both modes (4.5:1 text, 3:1 rings and edges).
- **Night panels** (sidebar, footer, tab bar, headline cards) are dark in
  both modes.
- **Fonts:** Overpass (headings) and Atkinson Hyperlegible Next (text),
  both SIL OFL, self-hosted with `@fontsource-variable`. Atkinson has only
  a slashed zero, so a `unicode-range` face gives it Overpass's digits:
  plain zeros everywhere, tabular in `.tabular` amounts.
- **Theme toggle** in every header: a real button with `aria-pressed`; the
  choice is saved in localStorage when allowed. The server never sets a
  theme, so without JavaScript the page follows the system.
- **Logo A** as `<x-logo>` (outlined wordmark, theme colors, `on-dark`,
  `mark-only`), icons and `site.webmanifest` in `public/`, built from
  `docs/brand/transit/`. The 16px favicon is simplified. Footer tagline:
  "Reroute your lunch."; the hero stays "You work here. Spend here."
- **The motif, used in three places only:** the landing hero's route line
  (Home, Desk, a local stop, Home, as an ordered list), `<x-eyebrow>`
  section labels, and the merchant rail on the wallet's merchant cards.
  Not on merchant candidates: they are not participants.
- **Buttons show every state:** pointer, hover, pressed, loading
  (`aria-busy`: spinner that turns only when motion is allowed, plus the
  form's busy text) and unavailable (the `unavailable:` variant, grey and
  readable). A second submit is cancelled at once, and the form's submit
  buttons are disabled one tick later, after the browser has collected the
  pressed button's value.
- **Disclosure menus** (`<x-menu>`): Account for every signed-in role;
  More when a role has over 5 pages in the sidebar or over 4 in the phone
  tab bar (fixed priority: Feedback stays visible for the platform admin,
  Merchants for the BID admin); View as for judges; Menu for the public
  site's section links on phones. Without JavaScript the lists simply show.

## Consequences

- New tests: `BrandLayoutTest`, `ThemeToggleTest`, `ButtonFeedbackTest`,
  `DropdownMenuTest`. Selector-only updates: `StyleguideTest`,
  `ToastTest`, `JudgeLandingTest`. No money, privacy or claims test changed.
- Honesty elements keep their exact wording; only their colors changed.
- `Role::navigation()` is unchanged: the More grouping happens in
  `app-shell`, so pages and permissions are untouched.
- New colors must be added in both the `@theme` block and the dark blocks,
  and any new text/background pair added to `ColorContrast::PAIRS`.
