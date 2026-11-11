---
name: Local Goods
description: Reroute your lunch. A calm, auditable benefits product drawn like a transit wayfinding map.
colors:
  page: "#ede8da"
  surface: "#f8f5ec"
  band: "#e3ddcd"
  ink: "#121820"
  muted: "#4a5058"
  line: "#c8bfa8"
  frame: "#121820"
  edge: "#7d7564"
  primary: "#0a6b67"
  primary-hover: "#08524f"
  primary-pressed: "#064441"
  on-primary: "#ffffff"
  primary-tint: "#d6e6e1"
  accent: "#e2603a"
  accent-hover: "#ec7d5a"
  on-accent: "#121820"
  accent-ink: "#a13a1b"
  route-2: "#7a3f9a"
  ok: "#1e6a3e"
  ok-bg: "#dcebdd"
  hold: "#834700"
  hold-bg: "#f6e5c4"
  danger: "#a8261e"
  danger-bg: "#f6dcd6"
  focus: "#5a2d86"
  disabled-ink: "#4a5058"
  disabled-bg: "#d9d2c0"
  sign: "#121820"
  night: "#121820"
  night-soft: "#1f2832"
  night-line: "#2f3a45"
  on-night: "#edf1f4"
  night-muted: "#b9c0c5"
  night-primary: "#42c4b9"
  night-accent: "#ff8a63"
  night-focus: "#d7b4ff"
  map-district: "#f7f3e8"
  map-street: "#fffdf7"
  map-street-case: "#bdb39b"
  map-label: "#5f5a4c"
typography:
  page-title:
    fontFamily: "Public Sans Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(2.25rem, 1.5rem + 3vw, 4rem)"
    fontWeight: 800
    lineHeight: 1.02
    letterSpacing: "-0.035em"
  page-lede:
    fontFamily: "Public Sans Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.1875rem"
    fontWeight: 400
    lineHeight: 1.6
  station-sign:
    fontFamily: "Public Sans Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.0625rem"
    fontWeight: 800
    lineHeight: 1.25
    letterSpacing: "0.06em"
  title:
    fontFamily: "Public Sans Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(2rem, 1.3rem + 3.5vw, 3rem)"
    fontWeight: 700
    lineHeight: 1.1
    letterSpacing: "-0.025em"
  body:
    fontFamily: "Public Sans Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
  body-small:
    fontFamily: "Public Sans Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.45
  eyebrow:
    fontFamily: "Public Sans Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 600
    letterSpacing: "0.1em"
  table-head:
    fontFamily: "Public Sans Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 700
    letterSpacing: "0.08em"
  ledger-number:
    fontFamily: "Overpass Mono Variable, ui-monospace, SFMono-Regular, Menlo, monospace"
    fontSize: "1rem"
    fontWeight: 400
    letterSpacing: "-0.01em"
rounded:
  control: "4px"
  panel: "6px"
  full: "9999px"
spacing:
  wrap-max: "1240px"
  wrap-gutter: "16px"
  wrap-gutter-wide: "32px"
  grid-gap: "24px"
  card-padding: "20px"
  card-padding-wide: "24px"
  tap-target: "44px"
  sidebar: "256px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.control}"
    padding: "12px 20px"
    height: "44px"
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
  button-primary-pressed:
    backgroundColor: "{colors.primary-pressed}"
  button-accent:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.on-accent}"
    rounded: "{rounded.control}"
    padding: "12px 20px"
    height: "44px"
  button-secondary:
    backgroundColor: "transparent"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "12px 20px"
    height: "44px"
  button-danger:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.danger}"
    rounded: "{rounded.control}"
    padding: "12px 20px"
    height: "44px"
  card-surface:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.panel}"
    padding: "20px"
  card-night:
    backgroundColor: "{colors.night}"
    textColor: "{colors.on-night}"
    rounded: "{rounded.panel}"
    padding: "20px"
  station-sign:
    backgroundColor: "{colors.sign}"
    textColor: "{colors.on-night}"
    typography: "{typography.station-sign}"
    rounded: "{rounded.control}"
    padding: "8px 16px"
    height: "48px"
  badge-ok:
    backgroundColor: "{colors.ok-bg}"
    textColor: "{colors.ok}"
    rounded: "3px"
    padding: "2px 8px"
  badge-hold:
    backgroundColor: "{colors.hold-bg}"
    textColor: "{colors.hold}"
    rounded: "3px"
    padding: "2px 8px"
  badge-neutral:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "3px"
    padding: "2px 8px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    height: "44px"
---

# Design System: Local Goods

## Overview

**Creative North Star: "Wayfinding"**

A transit map, not a dashboard. The page is warm map paper; sections are headed by dark station signs; the workday is a line with numbered stops; the district is drawn as a diagram. One teal line runs through the product and one coral stop marks the local place. Everything else is ink, rules and plain type. The interface should read as a serious, credible benefits product to an HR manager within ten seconds, and as something a tired employee can pay for lunch with on a phone.

This is direction A of the ADR 0038 redesign. Its night palette doubles as dark mode. Source of truth is `resources/css/app.css` (tokens, components) and `resources/views/components/`. The redesign rules are in `docs/redesign-rules.md`: content is locked, SVG and CSS only, self-hosted fonts, AA contrast on every pair.

**Key Characteristics:**
- Flat and ruled: heavy ink rules, 3px ink frames and ruled tables carry structure. There are no decorative shadows, gradients or glows.
- Station signs head the sections; stop numbers and a drawn line connect them.
- Teal is the line and the main action. Coral is the local stop and the single most important call to action.
- One family for everything (Public Sans); Overpass Mono only for ledger figures.
- Every state is visible: hover, pressed (1px down), busy (spinner), unavailable (grey, not see-through), focus (3px ring).
- 44px tap targets, 16px fields on phones. Dark mode redefines every token.
- Not yet tried with a screen reader or on a real phone.

## Colors

Warm paper with a cool ink and one teal and one coral. Values above are light mode. Dark mode (the night palette) redefines the same token names in two identical blocks in `app.css` (system setting unless the toggle chose light, and `[data-theme='dark']`), so every class switches with the theme.

### Primary
- **Route Teal** (`primary` #0a6b67; dark #42c4b9): the line, primary buttons, links. Hover #08524f, pressed #064441. `primary-tint` #d6e6e1 is the quiet teal fill.
- **Local Stop Coral** (`accent` #e2603a; dark #ff8a63): the local stop and the one call to action that matters most. Text on it is ink. Where coral is text, use `accent-ink` #a13a1b.
- **Second Line Plum** (`route-2` #7a3f9a): decoration only.

### Neutral
- **Map Paper** (`page` #ede8da; dark #0e1621): page background.
- **Raised Paper** (`surface` #f8f5ec; dark #172230): cards, panels, fields.
- **Band** (`band` #e3ddcd): section bands and quiet fills.
- **Ink** (`ink` #121820; dark #edf1f4): text, heavy rules, frames. `muted` #4a5058 is secondary text.
- **Frame** (`frame`, same as ink in light): panel and card borders and table head rules.
- **Line** (`line` #c8bfa8): thin decorative rules only, never a control boundary. **Edge** (`edge` #7d7564): control borders (3:1).

### Status
`ok` #1e6a3e on `ok-bg`, `hold` #834700 on `hold-bg`, `danger` #a8261e on `danger-bg`. Status is never color alone: a dot, word or flag goes with it.

### Station signs and night panels
`sign`, `night`, `night-soft`, `night-line`, `on-night`, `night-muted`, `night-primary`, `night-accent`, `night-focus`: dark in both themes (sidebar, footer, tab bar, station signs, wallet balance, headline-number cards). Focus on them is `night-focus` #d7b4ff.

### District diagram
`map-district`, `map-street`, `map-street-case`, `map-label` draw the district, its streets and labels. The route, stops and local stop use `primary`, `ink`, `surface` and `accent`.

### Named Rules
**The One Local Stop Rule.** Coral marks the local stop and the single call to action that matters most. Two coral things on a screen means one is wrong.

**The Line Is Not A Boundary Rule.** `line` is decoration; a control's border uses `edge`.

**The Pairs Are Tested Rule.** A new text/background pair goes in `App\Support\ColorContrast::PAIRS` and passes in light and dark. The logo is drawn from `primary`, `ink` and `accent` (and the night versions), so those keep the logo files' exact values.

## Typography

**One family:** Public Sans Variable (with ui-sans-serif, system-ui), self-hosted from `@fontsource-variable/public-sans`, for headings, body, labels and large money figures.
**Mono:** Overpass Mono Variable, from `@fontsource-variable/overpass-mono`, only for ledger tables and transaction amounts (`ledger-num`, and cells marked `.tabular` in `data-rtable` tables).

### Hierarchy
- **Page title** (`.page-title`; 800, `clamp(2.25rem, 1.5rem + 3vw, 4rem)`, line-height 1.02, tracking -0.035em, max 22ch): the h1 on inner pages.
- **Page lede** (`.page-lede`; 1.1875rem, 1.6, max 42rem): the sentence under it.
- **Station sign label** (800, 1.0625rem, +0.06em, uppercase): section heading inside a sign.
- **Title** (`text-title`; 700, clamp 2rem to 3rem): older page titles still using the utility.
- **Body** (1rem, 1.5) and **body small** (0.875rem, the workhorse in tables, hints and meta).
- **Eyebrow** (600, 0.875rem, +0.1em, uppercase), after a short route stop.
- **Table head** (700, 0.75rem, +0.08em, uppercase).
- **Money** (`money`, 700, tabular); **ledger numbers** (`ledger-num`, Overpass Mono, tabular).

Headings (`h1` to `h4`) use `-0.025em` tracking and `text-wrap: balance`. Tables use tabular, lining figures.

### Named Rules
**The Two Families Rule.** Public Sans plus Overpass Mono for figures. No third face. Hierarchy comes from size, weight and caps.

**The Plain Figures Rule.** Money uses tabular, lining figures so columns align.

## Layout

A centered `wrap` container, 1240px max, 16px gutters (32px from 768px), with one strong left edge that headings, text, tables and forms align to. `grid-12` gives twelve columns with a 24px gap. Body text, lists, tables and forms stay left-aligned; center only where it truly helps.

Signed-in pages sit in the app shell: a fixed 256px night sidebar from 1024px (grouped menus with a teal line and the current page as a coral stop), a sticky top bar, and on phones a night tab bar with the Feedback button above it. Inner pages open with `.page-head`: a station sign, the page title on the left edge, then the lede.

Page structure: sections hang off a drawn line as numbered stops (`.stop-line`, `.stop-line-dense`, `<x-stop-number>`), under a station sign. Other drawn lines: the landing `route-line`, the wallet's `merchant-line` (never for candidates), `step-line` tabs and `tour-line`. Heavy rules (`.rule-top` 3px, `.rule-heavy` 6px with a `.rule-tag` hanging from it) replace cards where a timetable-style break is enough. `.fare-card` is the example wallet.

Spacing is the Tailwind 4px scale; cards pad 20px, 24px from 640px. Tables use `<x-rtable>`: ordinary from 640px, each row a labelled card below. Long emails and ids break at `<wbr>` points. The footer clears the tab bar and `env(safe-area-inset-bottom)`.

### Named Rules
**The Phone Is The Card Rule.** Design the 320px phone first.

**The Honesty Line Rule.** Demo, projection and "not measured" labels never sit in anything hidden on phones.

## Elevation & Depth

Flat. Depth comes from rules and frames, and from the dark night panels, not from shadows. `box-shadow` is used only to draw parts: inset ink rings (stop numbers, 3 to 6px; scroll regions, 3px), halos that cut a stop out of its line (`0 0 0 3px` in page or night), and the focus ring.

- **Focus ring:** `outline: 3px solid var(--color-focus)`, offset 2px; `night-focus` on night panels.
- **Frames:** cards have a 2px `frame` border; the example wallet a 2px frame with a night head.

**The Flat At Rest Rule.** No decorative shadow, glow or blur.

## Shapes

Two corners: **4px** for buttons, fields, badges, menus and station signs (`rounded-lg`, `rounded-xl`), and **6px** for cards and panels (`rounded-2xl`, `rounded-3xl`). Badges use 3px. Pills (`rounded-full`) only for small round things: stop numbers, dots, the spinner, toggles. Dashed borders mark only "not measured" boxes. Control borders are 2px.

The route motif is geometry: a stop (`route-stop`, coral dot with an ink ring), the stop number (40px ringed circle, Overpass Mono digit), station sign dots (decoration, one coral for the local stop, hidden under 640px), the drawn line (teal, 4 to 8px).

Motion is minimal: 150ms color transitions, a 1px press, a spinner only when motion is allowed, and the diagram route draw. `prefers-reduced-motion` removes it all.

## Components

### Station sign (`<x-station-sign>`)
A dark band (`sign`, 48px min, 4px radius) holding the section title as a real heading (`as` h2/h3) in bold caps; optional dots on the right are `aria-hidden`.

### Stop number (`<x-stop-number>`)
Ringed 40px circle with the stop's number in Overpass Mono. Always `aria-hidden`.

### Buttons (`<x-button>`)
44px tall at every size on phones, bold, 4px radius. Variants: **primary** (teal), **accent** (coral, once per screen), **secondary** (2px ink outline that fills on hover), **ghost** (teal text), **danger** (2px danger outline), **inverse** and **inverse-outline** (on night panels). Pressed is 1px down; the `unavailable:` variant greys disabled and `aria-disabled` buttons; `form[data-busy]` adds a spinner and `aria-busy`.

### Cards (`<x-card>`)
6px radius, 20px padding (24px wide). Tones: **surface** (raised paper, 2px frame), **night** (headline numbers), **tint**, **band**. Do not nest cards.

### Badges (`<x-badge>`, `<x-status-badge>`)
3px radius, 12px bold text. Tones: neutral (ring), primary, ok, hold, danger, accent. Status badges carry a dot and a word.

### Fields
Label above in bold, hint and error below (`{id}-hint`, `{id}-error`, error has a flag). Border `edge`, fill `surface`, 44px min height, 16px text on phones, 3px focus ring.

### Tables (`<x-rtable>`, `data-rtable`)
Ruled, not boxed: a 3px frame rule under uppercase 12px column heads, thin `line` rows. Mono cells for amounts. Below 640px each row is a card with its column names. `table.statement` is the bank-statement variant (double rule under heads, totals between ink rules, right-aligned amounts).

### District diagram (`<x-district-diagram>`)
SVG: district, streets, a drawn route, stops and one larger local stop, labels on the map (hidden on phones), a key, and a screen-reader list of every stop. Stops may link to `#` fragments. Demo merchants are labelled as demo.

### Eyebrow, rules
`<x-eyebrow>` is a small caps label after a route stop. `.rule-tag` hangs from a heavy rule.

### Navigation
Grouped sidebar and phone Menu sheet (`<x-nav-groups>`, `<x-menu>`), the current page a coral stop on a teal line. Sidebar max five items, tab bar max four, the rest in More.

### Toast, theme toggle, logo
`<x-flash />` toast (focus moves to it after a jump); `<x-theme-toggle>`; `<x-logo>` (`on-dark`, `mark-only`), drawn with tokens, files in `docs/brand/transit/` untouched.

## Do's and Don'ts

### Do:
- Head each section with a station sign and give inner pages `.page-head`, `.page-title`, `.page-lede`.
- Keep teal as the line and main action; coral once per screen.
- Put control borders on `edge`, decoration on `line`.
- Use ruled tables, `<x-rtable>`, `<x-breakable>`, `<x-eyebrow>`; keep the two dark blocks identical.
- Show every state; keep 44px targets and 16px fields on phones.
- Add every new color pair to `ColorContrast::PAIRS`.

### Don't:
- Don't use gradients, glows, blur, emoji or decorative shadows.
- Don't add a font, or reintroduce Overpass or Atkinson for text, or the forest, cream, paper, amber, sage or awning of earlier looks.
- Don't use a second coral call to action, coral fills behind figures, or pills for anything big.
- Don't stack cards in cards, or box a table.
- Don't put the merchant line on a candidate.
- Don't use `break-all`, or hide honesty lines on phones.
- Don't convey status by color alone, and never remove, merge, hide or rename content or navigation in a design pass.
