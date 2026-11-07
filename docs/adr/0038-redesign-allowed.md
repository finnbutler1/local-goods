# 0038. Redesign allowed: a new visual system around the metro line and stop

**Status:** Accepted; direction chosen: A, "Wayfinding" (2026-11-07). Not
yet built. Supersedes the visual parts of ADR 0025.
No migration, money, ledger, Stripe, fraud prompt, AI input, eval data or
lock change.

## Context

A contest judge said Local Goods looks "vibe coded": a template-like
layout, generic fonts, and a centered hero, eyebrow and cards on nearly
every page. PRODUCT.md said the Transit lines brand (ADR 0025) was binding
and refine-only, so earlier design passes could only polish that look.
Polish didn't fix the impression. The product needs to read as serious
fintech that real businesses would trust, with a downtown-DC, community
feel, built around the metro line and stop.

## Decision

- **Redesign is allowed.** The fonts, the palette, the layout system, the
  components and the way the route motif is drawn may all change.
- **Kept exactly:** the name; the logo files in `docs/brand/transit/` and
  `public/` (no redraw); the metro line and stop motif; "Reroute your
  lunch." and "You work here. Spend here."; every honesty rule; the content
  lock (no wording, label, link, number, nav or button name changes).
- **Anchor colors:** the logo's teal (#0a6b67) and coral (#e2603a) stay.
  Everything else in the palette may be replaced.
- **Layout:** a 12-column grid in a centered container with a strong left
  edge; text is centered only where it truly helps. This replaces 0025's
  "center the hero, titles and main column" rule.
- **Kept from ADR 0025:** tokens named by role with dark mode redefining
  the same names; every new text/background pair in
  `ColorContrast::PAIRS` (light and dark); buttons showing every state
  (hover, pressed, busy, unavailable); disclosure menus; self-hosted fonts.
- **Process:** three directions are built as static HTML comps outside the
  app (`.impeccable/comps/`, git-ignored): A "Wayfinding" (transit
  signage, a district map as the hero), B "Neighborhood Ledger"
  (community-newspaper editorial, bank-statement ledger) and C "Night
  Line" (a dark city-at-night hero, light editorial sections). Finn picks
  one; it is then built in batches on a branch, each batch tested and
  approved before it is committed.

## Chosen direction: A, "Wayfinding"

Finn chose A on 2026-11-07: a transit-signage system on warm map paper.
B's bank-statement ledger and C's night palette are borrowed where noted.

- **Hero:** a transit diagram of the Golden Triangle drawn from the stored
  boundary (`data/sources/golden_triangle_bid.geojson`), with Connecticut
  Ave, K St, 17th St and Pennsylvania Ave as streets and the demo merchants
  as stops on one teal line, labelled "Demo merchants" in a map key.
  Farragut Grind is the one coral local stop. The headline is two lines on
  desktop ("You work here." / "Spend here."). The example wallet card sits
  below the map, with no drop shadow, so it never covers a street or stop.
  Map labels are hidden on phones, where the key and the workday strip
  carry the same facts.
- **Page structure:** sections are numbered stops on one route line down
  the page, each under a dark station-sign title band. Stop numbers are
  hidden from screen readers.
- **Type:** Public Sans (OFL) for everything, including large money
  figures (balances and totals: bold, tabular figures). Overpass Mono (OFL)
  only in ledger tables and transaction amounts. Both self-hosted.
- **Light palette:** map paper `#ede8da` with raised panels `#f8f5ec`, ink
  `#151b21`, muted `#4a5058`, logo teal `#0a6b67` for the line and main
  action, logo coral `#e2603a` for the one local stop and accent button
  (ink text), coral ink `#a13a1b` where coral is text, control edges
  `#7d7564`, focus `#5a2d86`. Every pair measured at WCAG AA in the comp.
- **Where the Money Is:** B's bank-statement treatment (statement layout,
  double-ruled totals) in A's fonts and colors. Other money screens get the
  same ledger treatment: tabular figures, plain ruled tables, calm and
  dense.
- **Dark mode:** based on C's night palette (night `#0e1621`, night soft
  `#172230`, on-night `#eef2f5`, night muted `#a7b3c0`, night teal
  `#45c6ba`, night coral `#ff8a63`, night focus `#d7b4ff`). Every pair
  stays at AA and goes in `ColorContrast::PAIRS`.
- **Motion:** the route line draws in once on load, only when the visitor
  allows motion.

## Consequences

- Brand tests that pin the old fonts or classes (for example
  `BrandLayoutTest`) may be updated during the build; each change is
  listed with its reason. Claims, honesty, privacy, accessibility and
  contrast tests are never weakened.
- DESIGN.md and `.impeccable/design.json` describe ADR 0025's look until
  the build finishes; they are rewritten from the built pages at the end.
- Decorative additions (stop numbers, map labels) are hidden from screen
  readers or plainly factual, and add no claims. Demo merchants on any map
  are labelled as demo. No stock photos, no AI images of people or real
  places, and no outside image or map services: SVG and CSS only.
